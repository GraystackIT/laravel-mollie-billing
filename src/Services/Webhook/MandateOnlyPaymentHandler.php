<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Events\TrialStarted;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MandateOnlyPaymentHandler
{
    /**
     * Subscription states a mandate-only activation may run from. `New` is the
     * regular first checkout; PastDue, Expired and a Cancelled subscription
     * whose grace period has run out are recovery checkouts — StartSubscriptionCheckout
     * admits all of them (it gates only on `! hasAccessibleBillingSubscription()`)
     * and routes 100%-coupon and trial checkouts through this handler, and
     * FirstPaymentHandler allows the same set. Anything still accessible was
     * already caught by openGate()'s duplicate branch and never reaches the activation.
     */
    protected const ACTIVATABLE_STATES = [
        SubscriptionStatus::New,
        SubscriptionStatus::PastDue,
        SubscriptionStatus::Expired,
        SubscriptionStatus::Cancelled,
    ];

    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly CouponService $couponService,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletUsageService $walletService,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paid(object $payment, Billable $billable, array $metadata = []): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $previousMandate = $billable->mollie_mandate_id;

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
        ])->save();

        event(new MandateUpdated($billable, $previousMandate, $billable->mollie_mandate_id));

        if (! empty($metadata['pending_subscription_plan_code'])) {
            $this->activateSubscriptionAfterMandate($payment, $billable, $metadata);
        }
    }

    /**
     * A failed/canceled/expired mandate-only payment is not a payment failure:
     * the €0 authorisation was aborted, but any existing subscription and its
     * mandate at Mollie are untouched — the billable must NOT become PastDue.
     * Only when there is no accessible subscription (checkout-time mandate flow
     * for trials / 100%-coupons) does the abort end the checkout.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(object $payment, Billable $billable, array $metadata = []): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        Log::info('Mandate-only payment did not complete', [
            'billable_id' => $billable->getKey(),
            'payment_id' => $payment->id ?? null,
            'status' => $payment->status ?? null,
        ]);

        if (! $billable->hasAccessibleBillingSubscription()) {
            MollieBilling::runAfterCheckout($billable, false);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function activateSubscriptionAfterMandate(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ((string) ($metadata['pending_subscription_plan_code'] ?? '') === '') {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');

        // Same two-phase split as FirstPaymentHandler, for the same reason: the gate
        // is short and transactional (row lock, guards, durable claim), the activation
        // runs outside it. With the Mollie CreateSubscription call inside the
        // transaction, anything throwing after it — an app listener, the
        // after-checkout hook — rolled back the trial state and
        // `mollie_subscription_id` while the subscription existed at Mollie, and the
        // re-delivery created a second one.
        $gate = $this->openGate($payment, $billable, $paymentId);

        if ($gate === ActivationGate::AlreadyBooked) {
            Log::info('Webhook re-delivery: invoice exists for mandate-only activation, skipping', [
                'payment_id' => $paymentId,
                'billable_id' => $billable->getKey(),
            ]);

            return;
        }

        if ($gate === ActivationGate::Duplicate) {
            Log::warning('Mandate-only subscription activation skipped — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'current_status' => $billable->getBillingSubscriptionStatus()->value,
            ]);
            DuplicatePaymentReceived::dispatch($billable, $paymentId);

            return;
        }

        try {
            $this->activateAfterMandate($payment, $billable, $metadata);
        } finally {
            $this->support->releaseActivationClaim($billable, $paymentId);
        }
    }

    /**
     * Decide under the row lock whether this delivery may activate, and claim it.
     *
     * The invoice guard is kept even though the trial branch writes no invoice: a
     * 100%-coupon activation does write one (the local zero-amount audit document),
     * and for the trial branch it simply never matches — the accessible Trial
     * subscription is what stops a re-run there, reported as the duplicate outcome.
     */
    protected function openGate(object $payment, Billable $billable, string $paymentId): ActivationGate
    {
        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($payment, $billable, $paymentId): ActivationGate {
            $billable->newQuery()->whereKey($billable->getKey())->lockForUpdate()->first();
            $billable->refresh();

            if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
                return ActivationGate::AlreadyBooked;
            }

            if ($billable->hasAccessibleBillingSubscription()) {
                return ActivationGate::Duplicate;
            }

            $this->support->claimActivation($billable, $paymentId);

            return ActivationGate::Proceed;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function activateAfterMandate(object $payment, Billable $billable, array $metadata): void
    {
        /** @var Model&Billable $billable */
        $planCode = (string) ($metadata['pending_subscription_plan_code'] ?? '');
        $interval = (string) ($metadata['pending_subscription_interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['pending_subscription_addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['pending_subscription_extra_seats'] ?? 0);
        $couponCode = (string) ($metadata['pending_subscription_coupon_code'] ?? '');
        $trialDays = (int) ($metadata['pending_subscription_trial_days'] ?? 0);

        $billable->forceFill([
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        // Activate FIRST so plan_code/interval/source land on the billable even when the
        // country-match check below cancels the subscription. CancelSubscription only
        // touches status + ends_at, so ResubscribeSubscription can still recover the
        // billable once the user resolves the mismatch.
        if ($trialDays > 0) {
            $this->activateTrialSubscriptionAfterMandate(
                $payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $couponCode, $trialDays
            );
        } else {
            $this->activateCouponSubscriptionAfterMandate(
                $payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $couponCode
            );
        }

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during mandate-only activation', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    protected function activateCouponSubscriptionAfterMandate(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        string $couponCode,
    ): void {
        /** @var Model&Billable $billable */

        if (! in_array($billable->getBillingSubscriptionStatus(), self::ACTIVATABLE_STATES, true)) {
            Log::info('Mandate-only coupon activation skipped — subscription is not in an activatable state', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_status' => $billable->getBillingSubscriptionStatus()->value,
            ]);

            return;
        }

        $pricedCoupon = $this->firstPaymentCouponPricing->price(
            $billable,
            $couponCode,
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );

        if ($pricedCoupon === null
            || $pricedCoupon['order_amount_net'] <= 0
            || $pricedCoupon['discount_net'] !== $pricedCoupon['order_amount_net']
        ) {
            Log::warning('Mandate-only subscription activation skipped — coupon does not produce 100% coverage', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'coupon_code' => $couponCode,
                'discount_net' => $pricedCoupon['discount_net'] ?? null,
                'order_amount_net' => $pricedCoupon['order_amount_net'] ?? null,
            ]);

            return;
        }

        if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
            Log::info('Webhook re-delivery: invoice exists for mandate-only activation, skipping', [
                'payment_id' => $payment->id ?? null,
                'billable_id' => $billable->getKey(),
            ]);
            return;
        }

        $billable->forceFill([
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'subscription_period_starts_at' => BillingTime::nowUtc(),
        ])->save();

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        $discountNet = (int) $pricedCoupon['discount_net'];
        $lineItems[] = [
            'kind' => 'coupon',
            'code' => (string) $pricedCoupon['coupon']->code,
            'label' => 'Coupon '.$pricedCoupon['coupon']->code,
            'quantity' => 1,
            'unit_price' => -$discountNet,
            'unit_price_net' => -$discountNet,
            'total_net' => -$discountNet,
        ];

        try {
            $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
        } catch (\Throwable $e) {
            Log::error('Mandate-only audit invoice creation failed', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // A Recurring coupon keeps discounting the renewals, so its discount must
        // reach CreateSubscription — and the marker has to exist *before* that
        // call: at 100% coverage Mollie cannot accept amount 0, so CreateSubscription
        // sends the full price and instead defers startDate past the marker's
        // valid_until. Without the marker it falls back to "one period from now"
        // and charges the full price from the second period on. redeem() below
        // re-writes the same marker; that is idempotent.
        $recurringDiscountNet = (int) ($pricedCoupon['recurring_discount_net'] ?? 0);
        if ($recurringDiscountNet > 0) {
            $this->couponService->applyRecurringMarker(
                $pricedCoupon['coupon'],
                $billable,
                (int) $pricedCoupon['order_amount_net'],
            );
        }

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => $recurringDiscountNet,
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            $this->support->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during mandate-only activation failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->support->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        $this->firstPaymentCouponPricing->redeem(
            $billable,
            $pricedCoupon,
            $planCode,
            $interval,
            (int) $invoice->id,
        );

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
        ])->save();

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    protected function activateTrialSubscriptionAfterMandate(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        string $couponCode,
        int $trialDays,
    ): void {
        /** @var Model&Billable $billable */

        if (! in_array($billable->getBillingSubscriptionStatus(), self::ACTIVATABLE_STATES, true)) {
            Log::info('Mandate-only trial activation skipped — subscription is not in an activatable state', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'current_status' => $billable->getBillingSubscriptionStatus()->value,
            ]);

            return;
        }

        $pricedCoupon = null;
        if ($couponCode !== '') {
            try {
                $pricedCoupon = $this->firstPaymentCouponPricing->price(
                    $billable, $couponCode, $planCode, $interval, $addonCodes, $extraSeats
                );
            } catch (\Throwable $e) {
                Log::warning('Trial activation: coupon pricing failed, proceeding without coupon', [
                    'billable_id' => $billable->getKey(),
                    'coupon_code' => $couponCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $billable->forceFill([
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'subscription_period_starts_at' => BillingTime::nowUtc(),
        ])->save();

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => 0,
                'mandate_id' => $billable->mollie_mandate_id,
                'trial_days' => $trialDays,
            ]);
        } catch (\Throwable $e) {
            Log::error('CreateSubscription during trial activation failed', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'plan_code' => $planCode,
                'interval' => $interval,
                'error' => $e->getMessage(),
            ]);

            event(new SubscriptionActivationFailed(
                $billable,
                $planCode,
                $interval,
                (string) ($payment->id ?? ''),
                null,
                $e->getMessage(),
            ));

            return;
        }

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => BillingTime::nowUtc()->addDays($trialDays),
        ])->save();

        if ($pricedCoupon !== null) {
            $this->stashTrialCoupon($billable, $pricedCoupon, $planCode, $interval, $addonCodes, $extraSeats);
        }

        $intervalDays = $interval === 'yearly' ? 365 : 30;
        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $included) {
            $included = (int) $included;
            if ($included <= 0) {
                continue;
            }
            $credit = (int) ceil($included * $trialDays / $intervalDays);
            if ($credit > 0) {
                $this->walletService->credit($billable, (string) $type, $credit, 'subscription_trial_start');
            }
        }

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new TrialStarted($billable, $planCode, $trialDays));

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * @param  array{coupon:\GraystackIT\MollieBilling\Models\Coupon, discount_net:int, recurring_discount_net?:int, order_amount_net:int}  $pricedCoupon
     * @param  array<int, string>  $addonCodes
     */
    protected function stashTrialCoupon(
        Billable $billable,
        array $pricedCoupon,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): void {
        /** @var Model&Billable $billable */
        $coupon = $pricedCoupon['coupon'];

        if ($coupon->type === CouponType::Recurring) {
            $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);
            $baseRecurringNet = SubscriptionAmount::net(
                $this->catalog, $billable, $planCode, $interval, $totalSeats, $addonCodes
            );
            $this->couponService->applyRecurringMarker($coupon, $billable, $baseRecurringNet);

            return;
        }

        if ($coupon->type === CouponType::SinglePayment) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['pending_first_charge_coupon'] = [
                'code' => (string) $coupon->code,
            ];
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }
}
