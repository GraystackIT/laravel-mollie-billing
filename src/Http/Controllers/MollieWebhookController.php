<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Http\Controllers;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Events\OneTimeOrderCompleted;
use GraystackIT\MollieBilling\Events\OneTimeOrderFailed;
use GraystackIT\MollieBilling\Events\OverageCharged;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Events\PaymentFailed;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Events\SubscriptionActivationFailed;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Exceptions\PaymentNotFoundException;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\InvoiceAvailableNotification;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

class MollieWebhookController extends Controller
{
    public function __construct(
        protected readonly InvoiceService $salesInvoiceService,
        protected readonly VatCalculationService $vatService,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly CreateSubscription $createSubscription,
        protected readonly CountryMatchService $countryMatchService,
        protected readonly WalletUsageService $walletService,
        protected readonly CouponService $couponService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $paymentId = (string) $request->input('id', '');

        Log::info('Webhook received', ['payment_id' => $paymentId]);

        if ($paymentId === '') {
            return response('', 200);
        }

        $reservation = $this->reserve($paymentId);
        if ($reservation === null) {
            Log::info('Webhook skipped — already processed or in progress', ['payment_id' => $paymentId]);
            return response('', 200);
        }

        try {
            $payment = $this->fetchPayment($paymentId);
        } catch (PaymentNotFoundException) {
            $reservation->delete();
            return response('', 200);
        } catch (\Throwable $e) {
            Log::warning('Mollie API lookup failed during webhook', ['id' => $paymentId, 'error' => $e->getMessage()]);
            $reservation->delete();
            return response('', 503);
        }

        $billable = $this->resolveBillableFromMetadata($payment);

        try {
            $this->route($payment, $billable);

            $reservation->update([
                'event_signature' => BillingProcessedWebhook::finalSignature($paymentId, (string) ($payment->status ?? 'unknown')),
                'processed_at' => BillingTime::nowUtc(),
            ]);

            return response('', 200);
        } catch (\Throwable $e) {
            Log::error('Billing webhook handler failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $reservation->delete();
            return response('', 500);
        }
    }

    protected function reserve(string $paymentId): ?BillingProcessedWebhook
    {
        $signature = BillingProcessedWebhook::pendingSignature($paymentId);

        $existingFinal = BillingProcessedWebhook::query()
            ->where('mollie_payment_id', $paymentId)
            ->where('event_signature', '!=', $signature)
            ->whereNotNull('processed_at')
            ->exists();

        if ($existingFinal) {
            return null;
        }

        $reservation = BillingProcessedWebhook::firstOrCreate(
            ['mollie_payment_id' => $paymentId, 'event_signature' => $signature],
            ['received_at' => BillingTime::nowUtc()],
        );

        if (! $reservation->wasRecentlyCreated) {
            return null;
        }

        return $reservation;
    }

    protected function fetchPayment(string $paymentId): object
    {
        try {
            return Mollie::send(new \Mollie\Api\Http\Requests\GetPaymentRequest($paymentId));
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            if (method_exists($e, 'getCode') && $e->getCode() === 404) {
                throw new PaymentNotFoundException($paymentId);
            }
            throw $e;
        }
    }

    protected function route(object $payment, ?Billable $billable): void
    {
        $status = (string) ($payment->status ?? 'unknown');
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['type'] ?? '');
        $subscriptionId = (string) ($payment->subscriptionId ?? '');

        Log::info('Webhook routing', [
            'payment_id' => $payment->id ?? null,
            'status' => $status,
            'type' => $type,
            'subscription_id' => $subscriptionId,
            'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
        ]);

        // Clear the pending-first-payment marker as soon as Mollie reports a
        // terminal state for that exact payment, regardless of branch. The
        // "waiting for payment" return page reads this to decide whether to
        // keep polling — leaving it set after a final state would extend the
        // poll loop unnecessarily.
        if ($billable !== null && in_array($status, ['paid', 'failed', 'canceled', 'expired'], true)) {
            $pendingId = $billable->getPendingFirstPaymentId();
            if ($pendingId !== null && $pendingId === (string) ($payment->id ?? '')) {
                $billable->clearPendingFirstPayment();
            }
        }

        if ($status === 'paid') {
            if ($billable === null) {
                Log::warning('Paid webhook with unresolvable billable', ['id' => $payment->id]);
                return;
            }

            if ($type === 'mandate_only') {
                $this->handleMandateOnlyPaid($payment, $billable, $metadata);
                return;
            }

            if ($type === 'one_time_order') {
                $this->handleOneTimeOrderPaid($payment, $billable, $metadata);
                return;
            }

            // Neuer Plan-Change-Pfad (Multi-VAT-Sammel-Charge).
            if ($type === 'prorata_charge') {
                $this->handleProrataChargePaid($payment, $billable, $metadata);
                if ($this->hasRefunds($payment)) {
                    $this->handleRefundWebhook($payment, $billable);
                }
                return;
            }

            if ($type === 'country_correction') {
                $this->handleCountryCorrectionPaid($payment, $billable, $metadata);
                if ($this->hasRefunds($payment)) {
                    $this->handleRefundWebhook($payment, $billable);
                }
                return;
            }

            if (in_array($type, ['overage', 'prorata', 'addon', 'seats'], true)) {
                $this->handleSingleChargePaid($payment, $billable, $type, $metadata);
                // Fall through to check for refunds on the same payment below.
            } elseif ($subscriptionId !== '') {
                $this->handleSubscriptionPaymentPaid($payment, $billable, $metadata);
            } elseif (($metadata['upgrade_from_local'] ?? false) === true) {
                $this->handleLocalToMollieUpgrade($payment, $billable, $metadata);
            } else {
                $this->handleFirstPaymentPaid($payment, $billable, $metadata);
            }

            // Sync any refunds that were issued via the Mollie dashboard.
            // This runs *after* the normal paid-flow so that a payment that
            // is both paid and partially refunded is fully processed.
            if ($this->hasRefunds($payment)) {
                $this->handleRefundWebhook($payment, $billable);
            }

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            if ($billable === null) {
                return;
            }

            if ($type === 'one_time_order') {
                $this->handleOneTimeOrderFailed($payment, $billable, $metadata);
                return;
            }

            if ($type === 'prorata_charge') {
                $this->handleProrataChargeFailed($payment, $billable, $metadata);
                return;
            }

            if ($type === 'country_correction') {
                $this->handleCountryCorrectionFailed($payment, $billable, $metadata);
                return;
            }

            if (in_array($type, ['overage', 'prorata', 'addon', 'seats'], true)) {
                $this->handleSingleChargeFailed($payment, $billable, $type);
                return;
            }

            // First checkout payment that never resulted in a subscription.
            if ($subscriptionId === '' && ! $billable->hasAccessibleBillingSubscription()) {
                MollieBilling::runAfterCheckout($billable, false);
                return;
            }

            $this->handleSubscriptionPaymentFailed($payment, $billable);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function handleMandateOnlyPaid(object $payment, Billable $billable, array $metadata = []): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $previousMandate = $billable->mollie_mandate_id;

        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
        ])->save();

        event(new MandateUpdated($billable, $previousMandate, $billable->mollie_mandate_id));

        // 100%-single_payment-coupon flow: the checkout routed to the Mandate-Only
        // path because the first charge was 0 €. Activate the subscription now that
        // the mandate is captured. The Mollie subscription is created with the
        // default startDate = now + 1 interval, so the next period bills at full price.
        if (! empty($metadata['pending_subscription_plan_code'])) {
            $this->activateSubscriptionAfterMandate($payment, $billable, $metadata);
        }
    }

    /**
     * Activate a subscription after a 0-EUR Mandate-Only payment for the
     * 100%-single_payment-coupon flow. Mirrors handleFirstPaymentPaid()'s
     * post-mandate steps without re-using it (handleFirstPaymentPaid expects
     * a non-zero charge and recomputes the coupon discount against the paid
     * gross — neither applies here).
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function activateSubscriptionAfterMandate(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['pending_subscription_plan_code'] ?? '');
        $interval = (string) ($metadata['pending_subscription_interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['pending_subscription_addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['pending_subscription_extra_seats'] ?? 0);
        $couponCode = (string) ($metadata['pending_subscription_coupon_code'] ?? '');

        if ($planCode === '') {
            return;
        }

        if ($billable->hasAccessibleBillingSubscription()) {
            Log::warning('Mandate-only subscription activation skipped — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
            ]);
            DuplicatePaymentReceived::dispatch($billable, (string) ($payment->id ?? ''));

            return;
        }

        // Persist payment-country + run country-mismatch check, mirroring persistFirstPaymentArtifacts().
        $billable->forceFill([
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during mandate-only activation', ['error' => $e->getMessage()]);
        }

        // Coupon must validate to a 100% discount; if not, abort the activation
        // (the customer hasn't paid, and we won't activate without a valid pricing path).
        $pricedCoupon = $this->priceFirstPaymentCoupon(
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

        // The Audit-Invoice's line items live in the period [now, now+1 interval]. Set
        // subscription_period_starts_at first so InvoiceService::createForPayment uses
        // the correct period for its line_items (it reads getBillingPeriodStartsAt()).
        $billable->forceFill([
            'subscription_plan_code' => $planCode,
            'subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::from($interval),
            'subscription_period_starts_at' => BillingTime::nowUtc(),
        ])->save();

        // Build line items: positive plan/seat/addon lines + a single negative
        // coupon line. createForPayment computes VAT per line via
        // vatService->calculate(country, summedNet=0, billable) — the rate comes from
        // the country lookup (not collapsed to 0%), so plan + coupon lines net out
        // to 0 € gross with consistent vat_rate.
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

        try {
            $this->createSubscription->handle($billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => 0,
                'mandate_id' => $billable->mollie_mandate_id,
            ]);
        } catch (\Throwable $e) {
            $this->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during mandate-only activation failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        $this->redeemPricedFirstPaymentCoupon(
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

        // Hydrate wallets — same semantics as handleFirstPaymentPaid().
        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    protected function handleFirstPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        if ($billable->hasAccessibleBillingSubscription()) {
            Log::warning('Duplicate first-payment received — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
            ]);

            DuplicatePaymentReceived::dispatch($billable, (string) ($payment->id ?? ''));

            return;
        }

        $invoice = $this->persistFirstPaymentArtifacts($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);

        $pricedCoupon = $this->priceFirstPaymentCoupon(
            $billable,
            (string) ($metadata['coupon_code'] ?? ''),
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );
        $recurringDiscountNet = $pricedCoupon['recurring_discount_net'] ?? 0;

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
            $this->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during webhook failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        if ($pricedCoupon !== null) {
            $this->redeemPricedFirstPaymentCoupon(
                $billable,
                $pricedCoupon,
                $planCode,
                $interval,
                (int) $invoice->id,
            );
        }

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Mollie,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => BillingTime::nowUtc(),
        ])->save();

        // Hydrate wallets with the new plan's included usages. CreateSubscription
        // intentionally does not touch the wallet — that responsibility belongs
        // to the caller, since first-payment, Local→Mollie upgrade and resubscribe
        // each need different hydration semantics.
        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * Convert a local subscription to a Mollie subscription after the first
     * payment has been confirmed. Reuses the local plan/interval as the *old*
     * state and rebalances the wallet so purchased credits and any remaining
     * plan quota are preserved across the conversion.
     */
    protected function handleLocalToMollieUpgrade(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        $oldPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $oldInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        $invoice = $this->persistFirstPaymentArtifacts($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats);

        $pricedCoupon = $this->priceFirstPaymentCoupon(
            $billable,
            (string) ($metadata['coupon_code'] ?? ''),
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );
        $recurringDiscountNet = $pricedCoupon['recurring_discount_net'] ?? 0;

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
            $this->reportSubscriptionActivationFailure(
                $billable,
                $planCode,
                $interval,
                $invoice,
                $payment,
                'CreateSubscription during local upgrade failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        if ($pricedCoupon !== null) {
            $this->redeemPricedFirstPaymentCoupon(
                $billable,
                $pricedCoupon,
                $planCode,
                $interval,
                (int) $invoice->id,
            );
        }

        // Rebalance wallets: plan credits proratised against actual usage,
        // purchased credits preserved, overage charged via mandate if needed.
        if ($oldPlan !== '') {
            app(\GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster::class)
                ->adjust($billable, $oldPlan, $oldInterval, $planCode, $interval);
        }

        event(new \GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal(
            $billable,
            $oldPlan,
            $oldInterval,
            $planCode,
            $interval,
        ));
        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }

    /**
     * Persist mandate, customer ID, country, and create the invoice for a first
     * payment (real first-time activation or local→Mollie upgrade).
     */
    protected function persistFirstPaymentArtifacts(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): \GraystackIT\MollieBilling\Models\BillingInvoice {
        $billable->forceFill([
            'mollie_customer_id' => (string) ($payment->customerId ?? $billable->mollie_customer_id),
            'mollie_mandate_id' => (string) ($payment->mandateId ?? $billable->mollie_mandate_id),
            'tax_country_payment' => strtoupper((string) ($payment->countryCode ?? $billable->tax_country_payment ?? '')),
        ])->save();

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed', ['error' => $e->getMessage()]);
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);

        return $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);
    }

    protected function handleSubscriptionPaymentPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $planCode = $billable->getBillingSubscriptionPlanCode() ?? (string) ($metadata['plan_code'] ?? '');
        $interval = $billable->getBillingSubscriptionInterval() ?? (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = $billable->getActiveBillingAddonCodes();
        $extraSeats = $billable->getExtraBillingSeats();

        $newPaymentCountry = strtoupper((string) ($payment->countryCode ?? ''));
        if ($newPaymentCountry !== '' && $newPaymentCountry !== strtoupper((string) ($billable->tax_country_payment ?? ''))) {
            $billable->forceFill(['tax_country_payment' => $newPaymentCountry])->save();
        }

        try {
            $this->countryMatchService->check($billable);
        } catch (\Throwable $e) {
            Log::warning('Country match check failed during recurring', ['error' => $e->getMessage()]);
        }

        $seats = $this->catalog->includedSeats($planCode) + $extraSeats;
        $expectedNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);
        $couponDiscountNet = $this->couponService->computeMarkerDiscount($billable, $expectedNet);
        $netForCharge = max(0, $expectedNet - $couponDiscountNet);
        $vat = $this->vatService->calculate((string) ($billable->getBillingCountry() ?? ''), $netForCharge, $billable);
        $actualGross = $this->amountFromMolliePayment($payment);

        if (abs($actualGross - (int) $vat['gross']) > 1) {
            event(new PaymentAmountMismatch($billable, (string) $payment->id, (int) $vat['gross'], $actualGross));
        }

        $lineItems = SubscriptionAmount::lineItems($this->catalog, $billable, $planCode, $interval, $extraSeats, $addonCodes);
        if ($couponDiscountNet > 0) {
            $vatRate = (float) ($vat['rate'] ?? 0.0);
            $vatAmount = (int) round($couponDiscountNet * $vatRate / 100);
            $lineItems[] = [
                'kind' => 'coupon',
                'description' => 'Coupon discount',
                'qty' => 1,
                'unit_price_net' => -$couponDiscountNet,
                'amount_net' => -$couponDiscountNet,
                'vat_rate' => $vatRate,
                'vat_amount' => -$vatAmount,
                'amount_gross' => -($couponDiscountNet + $vatAmount),
            ];
        }
        $invoice = $this->salesInvoiceService->createForPayment($payment, 'subscription', $lineItems, $billable);

        if ($couponDiscountNet > 0) {
            try {
                $this->couponService->redeemRecurringCouponForRenewal(
                    $billable,
                    $expectedNet,
                    (int) $invoice->id,
                );
            } catch (\Throwable $e) {
                Log::warning('Recurring coupon redemption failed during renewal webhook', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->couponService->markerExpired($billable)) {
            try {
                app(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class)
                    ->updateRecurringAmount(
                        billable: $billable,
                        planCode: $planCode,
                        interval: $interval,
                        addons: $addonCodes,
                        extraSeats: $extraSeats,
                        intervalChanged: false,
                        couponDiscountNet: 0,
                    );
                $this->couponService->clearActiveRecurringCouponMarker($billable);
            } catch (\Throwable $e) {
                Log::warning('Mollie reset PATCH after coupon expiry failed — queued for retry', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_subscription_patch'] = [
                    'reason' => 'recurring_coupon_expiry_reset',
                    'plan_code' => $planCode,
                    'interval' => $interval,
                    'addons' => $addonCodes,
                    'extra_seats' => $extraSeats,
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ];
                $billable->forceFill(['subscription_meta' => $meta])->save();
            }
        }

        // Recharge wallets: reset or add based on rollover configuration.
        $rollover = $this->catalog->usageRollover($planCode);

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $units) {
            try {
                if ($rollover) {
                    // Update purchased balance before adding new plan quota — the
                    // current balance reflects consumption from the past period.
                    $wallet = $billable->getWallet((string) $type);
                    if ($wallet !== null) {
                        $purchasedRemaining = WalletUsageService::computePurchasedRemaining(
                            WalletUsageService::getPurchasedBalance($wallet),
                            (int) $wallet->balanceInt,
                        );
                        WalletUsageService::setPurchasedBalance($wallet, $purchasedRemaining);
                    }

                    $this->walletService->credit($billable, (string) $type, (int) $units, 'subscription_renewal_rollover');
                } else {
                    // resetAndCredit handles purchased balance internally.
                    $this->walletService->resetAndCredit($billable, (string) $type, (int) $units, 'subscription_renewal');
                }
            } catch (\Throwable $e) {
                Log::warning('Wallet credit failed during webhook', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $paidAt = $payment->paidAt ?? null;
        $periodStartsAt = $paidAt ? \Carbon\Carbon::parse((string) $paidAt)->setTimezone("UTC") : BillingTime::nowUtc();

        // Sync seat_count from the actually-paid invoice line_items so that pro-rata
        // math on future plan-changes operates on the real paid-for state.
        $derivedSeatCount = $invoice->deriveSeatCount();
        $meta = $billable->getBillingSubscriptionMeta();
        if ($derivedSeatCount !== null) {
            $meta['seat_count'] = $derivedSeatCount;
        }

        $billable->forceFill([
            'subscription_period_starts_at' => $periodStartsAt,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentSucceeded($billable, $invoice));

        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSingleChargePaid(object $payment, Billable $billable, string $type, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $lineItems = (array) ($metadata['line_items'] ?? []);
        if (empty($lineItems)) {
            $actual = $this->amountFromMolliePayment($payment);
            $lineItems = [[
                'kind' => $type,
                'label' => ucfirst($type).' charge',
                'quantity' => 1,
                'unit_price_net' => $actual,
                'unit_price' => $actual,
                'total_net' => $actual,
            ]];
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, $type, $lineItems, $billable);

        if ($type === 'overage') {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['usage_overage'], $meta['usage_overage_status'], $meta['usage_overage_attempts']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            event(new OverageCharged($billable, $invoice, $lineItems));
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true)) {
            $meta = $billable->getBillingSubscriptionMeta();
            unset($meta['prorata_pending_payment_id']);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            // Apply the deferred plan change now that payment succeeded.
            $billable->refresh();
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;

            Log::info('Prorata payment processed, checking for pending plan change', [
                'billable' => $billable->getKey(),
                'has_pending' => ! empty($pendingChange),
                'pending_plan' => $pendingChange['plan_code'] ?? null,
            ]);

            if (! empty($pendingChange)) {
                try {
                    app(UpdateSubscription::class)->applyPendingPlanChange($billable, $invoice);
                } catch (\Throwable $e) {
                    Log::error('Failed to apply pending plan change after prorata payment', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);

                    // Clear pending state so the UI stops polling and shows the failure.
                    app(UpdateSubscription::class)->clearPendingPlanChange($billable);
                    $billable->refresh();

                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                    $meta['plan_change_failed_reason'] = $e->getMessage();
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $e->getMessage()));

                    $recipients = MollieBilling::notifyBillingAdmins($billable);
                    if (! empty($recipients)) {
                        Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                    }
                }
            }
        }

        event(new PaymentSucceeded($billable, $invoice));
        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleSubscriptionPaymentFailed(object $payment, Billable $billable): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['payment_failure'] = [
            'payment_id' => (string) $payment->id,
            'failed_at' => BillingTime::nowUtc()->toIso8601String(),
            'reason' => (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown'),
        ];

        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $meta,
        ])->save();

        event(new PaymentFailed($billable, (string) $payment->id, $meta['payment_failure']['reason']));

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send($recipients, new SubscriptionPaymentFailedNotification($billable, (string) $payment->id));
        }
    }

    protected function handleSingleChargeFailed(object $payment, Billable $billable, string $type): void
    {
        if ($type === 'overage' && $billable instanceof \Illuminate\Database\Eloquent\Model) {
            RetryUsageOverageChargeJob::dispatch(
                $billable->getMorphClass(),
                $billable->getKey(),
                1,
            );
            return;
        }

        if (in_array($type, ['prorata', 'addon', 'seats'], true) && $billable instanceof \Illuminate\Database\Eloquent\Model) {
            $pendingChange = $billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null;
            $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');

            app(UpdateSubscription::class)->clearPendingPlanChange($billable);

            if ($pendingChange) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
                $meta['plan_change_failed_reason'] = $reason;
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new PlanChangeFailed($billable, $pendingChange, (string) $payment->id, $reason));

                $recipients = MollieBilling::notifyBillingAdmins($billable);
                if (! empty($recipients)) {
                    Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
                }
            }
        }

        event(new PaymentFailed($billable, (string) $payment->id, (string) ($payment->status ?? 'unknown')));
    }

    /**
     * Phase-2-Trigger für die neue Plan-Change-Charge-Logik (Multi-VAT-Sammel-Charges).
     *
     * 1. Charge-Invoice via InvoiceService::createInvoice persistieren.
     * 2. Mollie-Subscription-PATCH via MollieSubscriptionPatcher::updateForIntent.
     * 3. Geplante Refunds (aus Pending-State) via InvoiceService::createRefund ausführen.
     * 4. Pending-State löschen.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function handleProrataChargePaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $pending = $billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null;
        if (empty($pending)) {
            // Pending-State fehlt — möglicherweise schon verarbeitet (idempotent) oder Out-of-Band.
            // createInvoice ist idempotent via UNIQUE-Index auf mollie_payment_id.
            return;
        }

        $invoiceService = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class);
        $patcher = app(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class);

        // Decide upfront whether this charge starts a new billing period (plan/interval change)
        // or extends the running one (mid-cycle seats/addons). Same logic that step 4 below uses,
        // hoisted so the new period applies consistently to the charge invoice + line_items.
        $intentData = is_array($pending['intent'] ?? null) ? $pending['intent'] : null;
        $oldPlan = $intentData !== null ? (string) ($intentData['current_plan'] ?? '') : '';
        $oldInterval = $intentData !== null ? (string) ($intentData['current_interval'] ?? '') : '';
        $newPlanCode = $intentData !== null
            ? (string) ($intentData['new_plan'] ?? $billable->getBillingSubscriptionPlanCode())
            : (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $newIntervalCode = $intentData !== null
            ? (string) ($intentData['new_interval'] ?? $billable->getBillingSubscriptionInterval())
            : (string) ($billable->getBillingSubscriptionInterval() ?? '');
        $startsNewPeriod = $oldPlan !== '' && ($oldPlan !== $newPlanCode || $oldInterval !== $newIntervalCode);

        $paidAt = $payment->paidAt ?? null;
        $newPeriodStart = $paidAt ? \Carbon\Carbon::parse((string) $paidAt)->setTimezone("UTC") : BillingTime::nowUtc();
        $newPeriodEnd = $newIntervalCode === 'yearly'
            ? $newPeriodStart->copy()->addYear()
            : $newPeriodStart->copy()->addMonth();

        $invoicePeriodStart = $startsNewPeriod ? $newPeriodStart : $billable->getBillingPeriodStartsAt();
        $invoicePeriodEnd = $startsNewPeriod ? $newPeriodEnd : $billable->nextBillingDate();

        // Charge lines come from the persisted pending state (Mollie metadata is capped at 1024 bytes,
        // so we keep the full payload in subscription_meta instead of round-tripping it via Mollie).
        // Fallback to legacy metadata.line_items for in-flight charges that were created before this change.
        $lineItems = (array) ($pending['charge_lines'] ?? $metadata['line_items'] ?? []);

        // For plan/interval changes the charge buys the full new period — overwrite each line's
        // period to the new window so currentPeriodLines() can later use these as refund sources.
        if ($startsNewPeriod && $invoicePeriodStart !== null && $invoicePeriodEnd !== null) {
            $startIso = $invoicePeriodStart->toIso8601String();
            $endIso = $invoicePeriodEnd->toIso8601String();
            $lineItems = array_map(static function (array $line) use ($startIso, $endIso): array {
                $line['period_start'] = $startIso;
                $line['period_end'] = $endIso;
                return $line;
            }, $lineItems);
        }

        // 1. Charge-Invoice persistieren.
        try {
            $chargeInvoice = $invoiceService->createInvoice(
                billable: $billable,
                kind: \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
                molliePaymentId: (string) $payment->id,
                mollieSubscriptionId: $payment->subscriptionId ?? null,
                lineItems: $lineItems,
                periodStart: $invoicePeriodStart,
                periodEnd: $invoicePeriodEnd,
            );
            event(new PaymentSucceeded($billable, $chargeInvoice));
            $this->notifyInvoiceAvailable($billable, $chargeInvoice);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to persist prorata_charge invoice', [
                'billable' => $billable->getKey(),
                'payment_id' => (string) $payment->id,
                'error' => $e->getMessage(),
            ]);
            // Pending-State NICHT löschen — Retry möglich.
            return;
        }

        // 2. Mollie-Subscription-PATCH.
        try {
            $intent = \GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent::fromArray($pending['intent']);
            $patcher->updateForIntent($billable, $intent);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Mollie subscription PATCH failed in Phase 2 — will be inconsistent until next recurring run', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
            // Nicht abbrechen — der Recurring-Webhook nutzt live Billable-State, ist also selbst-heilend.
        }

        // 3. Refunds.
        $refundLinesData = (array) ($pending['refund_lines'] ?? []);
        if (! empty($refundLinesData)) {
            $refundLines = array_map(
                fn (array $data) => \GraystackIT\MollieBilling\Support\ProrataLine::fromArray($data),
                $refundLinesData,
            );
            try {
                $invoiceService->createRefund($billable, $refundLines, 'Plan change refund');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to create refund invoice in Phase 2', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Plan/Sitze/Addons auf den Billable schreiben (Periode bereits oben berechnet).
        $billable->refresh();
        $wasPastDue = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue;
        if ($intentData !== null) {
            $meta = $billable->getBillingSubscriptionMeta();

            // Authoritative seat_count: prefer the freshly-persisted charge invoice's
            // line_items (matches what Mollie just charged). Fallback to intent.new_seats
            // (pure-refund flows have no charge invoice).
            $derivedFromCharge = isset($chargeInvoice) ? $chargeInvoice->deriveSeatCount() : null;
            $meta['seat_count'] = $derivedFromCharge ?? (int) ($intentData['new_seats'] ?? $billable->getBillingSeatCount());

            unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);

            // Past-Due-Reset: this charge cleared the failed-payment state. The
            // payment_failure marker no longer reflects reality and the status
            // returns to Active. The new period started at $newPeriodStart (set
            // via $startsNewPeriod above); subscription_ends_at must be cleared
            // because Mollie's PATCH (with forceResetStartDate) reset the
            // recurring schedule to now + 1 interval.
            if ($wasPastDue) {
                unset($meta['payment_failure']);
            }

            $billable->forceFill([
                'subscription_plan_code' => $newPlanCode,
                'subscription_interval' => $newIntervalCode,
                'active_addon_codes' => array_keys((array) ($intentData['new_addons'] ?? [])),
                'subscription_meta' => $meta,
                ...($startsNewPeriod ? ['subscription_period_starts_at' => $newPeriodStart] : []),
                ...($wasPastDue ? [
                    'subscription_status' => SubscriptionStatus::Active,
                    'subscription_ends_at' => null,
                ] : []),
            ])->save();
        }

        // 5. Wallets rebalancen, falls Plan/Interval gewechselt wurden.
        // Mirror UpdateSubscription::update() — der Adjuster reset alte Plan-Quotas und
        // kreditet die neuen, behält gekaufte Credits, und chargt unaufgelöste Overage.
        $billable->refresh();
        if ($startsNewPeriod) {
            try {
                app(\GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster::class)
                    ->adjust($billable, $oldPlan, $oldInterval, $newPlanCode, $newIntervalCode);
            } catch (\Throwable $e) {
                Log::warning('Wallet adjust during prorata charge phase 2 failed', [
                    'billable' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 6. Pending-State löschen — alle Marker, damit der nächste Plan-Change nicht blockiert wird.
        $billable->refresh();
        $meta = $billable->getBillingSubscriptionMeta();
        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Charge-Webhook failed: Pending-State löschen, kein PATCH-Rollback nötig (PATCH lief noch nicht).
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function handleProrataChargeFailed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $pending = $meta['pending_prorata_change'] ?? null;
        unset(
            $meta['pending_prorata_change'],
            $meta['pending_plan_change'],
            $meta['prorata_pending_payment_id'],
        );

        $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');
        $meta['plan_change_failed_at'] = BillingTime::nowUtc()->toIso8601String();
        $meta['plan_change_failed_reason'] = $reason;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        if ($pending !== null) {
            event(new PlanChangeFailed($billable, $pending, (string) $payment->id, $reason));

            $recipients = MollieBilling::notifyBillingAdmins($billable);
            if (! empty($recipients)) {
                Notification::send($recipients, new PlanChangeFailedNotification($billable, (string) $payment->id));
            }
        }
    }

    /**
     * Country-mismatch correction reissue: create the BillingInvoice for a
     * confirmed correction payment, link it back to the mismatch's audit log,
     * and clear the pending state.
     */
    protected function handleCountryCorrectionPaid(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');
        $mismatchId = (int) ($metadata['mismatch_id'] ?? 0);
        $originalInvoiceId = (int) ($metadata['original_invoice_id'] ?? 0);

        if ($mismatchId === 0 || $originalInvoiceId === 0) {
            Log::warning('country_correction webhook missing metadata', [
                'payment_id' => $paymentId,
                'metadata' => $metadata,
            ]);
            return;
        }

        // Idempotency: if we've already created an invoice for this payment, no-op.
        $existing = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();
        if ($existing !== null) {
            return;
        }

        $original = BillingInvoice::query()->whereKey($originalInvoiceId)->first();
        if ($original === null) {
            Log::warning('country_correction webhook: original invoice not found', [
                'payment_id' => $paymentId,
                'original_invoice_id' => $originalInvoiceId,
            ]);
            return;
        }

        try {
            $invoice = $this->salesInvoiceService->createCorrectionInvoice($payment, $original, $billable);
        } catch (\Throwable $e) {
            Log::error('country_correction reissue failed during webhook', [
                'payment_id' => $paymentId,
                'mismatch_id' => $mismatchId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $mismatch = \GraystackIT\MollieBilling\Models\BillingCountryMismatch::query()->whereKey($mismatchId)->first();
        if ($mismatch !== null) {
            $log = $mismatch->correctionInvoicesData();
            $reissued = $log['reissued'];
            $found = false;
            foreach ($reissued as &$entry) {
                if ((int) ($entry['original_invoice_id'] ?? 0) === $originalInvoiceId
                    && (string) ($entry['mollie_payment_id'] ?? '') === $paymentId) {
                    $entry['invoice_id'] = (int) $invoice->id;
                    $found = true;
                    break;
                }
            }
            unset($entry);
            if (! $found) {
                $reissued[] = [
                    'original_invoice_id' => $originalInvoiceId,
                    'mollie_payment_id' => $paymentId,
                    'invoice_id' => (int) $invoice->id,
                ];
            }
            $log['reissued'] = $reissued;

            $mismatch->forceFill([
                'correction_invoices' => $log,
                'corrective_invoice_id' => (int) $invoice->id,
            ])->save();
        }

        event(new PaymentSucceeded($billable, $invoice));
    }

    /**
     * Country-mismatch correction reissue failed at Mollie. Roll the mismatch
     * back to Pending so the user can retry the resolve flow, drop the pending
     * country_corrections entry so a future retry isn't double-counted, and
     * notify the billable's admins so manual follow-up is possible.
     */
    protected function handleCountryCorrectionFailed(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof \Illuminate\Database\Eloquent\Model)) {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');
        $mismatchId = (int) ($metadata['mismatch_id'] ?? 0);
        $reason = (string) ($payment->details->failureReason ?? $payment->status ?? 'unknown');

        $meta = $billable->getBillingSubscriptionMeta();
        if (isset($meta['country_corrections'][$paymentId])) {
            unset($meta['country_corrections'][$paymentId]);
            if (empty($meta['country_corrections'])) {
                unset($meta['country_corrections']);
            }
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }

        if ($mismatchId === 0) {
            return;
        }

        $mismatch = \GraystackIT\MollieBilling\Models\BillingCountryMismatch::query()->whereKey($mismatchId)->first();
        if ($mismatch === null) {
            return;
        }

        $mismatch->forceFill([
            'status' => \GraystackIT\MollieBilling\Enums\CountryMismatchStatus::Pending,
        ])->save();

        Log::warning('country_correction reissue failed at Mollie', [
            'mismatch_id' => $mismatchId,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (! empty($recipients)) {
            Notification::send(
                $recipients,
                new \GraystackIT\MollieBilling\Notifications\CountryMismatchReissueFailedNotification($billable, $mismatch, $reason),
            );
        }
    }

    /**
     * Sync refunds initiated via the Mollie dashboard into local credit notes.
     *
     * Each Mollie refund has a unique ID (e.g. re_xxx). We store it in the
     * credit note's mollie_payment_id as "{paymentId}:re:{refundId}" so we
     * can deduplicate by refund ID rather than by amount (which would fail
     * for multiple partial refunds of the same value).
     */
    protected function handleRefundWebhook(object $payment, Billable $billable): void
    {
        $refunds = [];
        try {
            /** @phpstan-ignore-next-line — SDK magic. */
            $refunds = $payment->refunds();
        } catch (\Throwable) {
            return;
        }

        $original = BillingInvoice::query()
            ->where('mollie_payment_id', (string) $payment->id)
            ->first();

        if ($original === null) {
            return;
        }

        foreach ($refunds as $refund) {
            $refundId = (string) ($refund->id ?? '');
            if ($refundId === '') {
                continue;
            }

            // Idempotenz-Check: existiert eine Refund-Invoice, die diese mollie_refund_id in
            // einem ihrer line_items trägt?
            if ($this->refundIdAlreadyPersisted($original->billable_type, $original->billable_id, $refundId)) {
                continue;
            }

            $refundAmountCents = (int) round(((float) ($refund->amount->value ?? 0)) * 100);

            // Convert gross refund to net using the rate from the original invoice's first line item.
            $originalLines = (array) ($original->line_items ?? []);
            $firstLine = $originalLines[0] ?? null;
            $rate = $firstLine !== null && isset($firstLine['vat_rate']) ? (float) $firstLine['vat_rate'] : 0.0;
            $netAmount = $rate > 0
                ? (int) round($refundAmountCents / (1 + $rate / 100))
                : $refundAmountCents;

            $creditNote = $this->salesInvoiceService->createCreditNote($original, $netAmount);
            // mollie_refund_id in line_items eintragen für Idempotenz beim nächsten Webhook.
            $lines = (array) $creditNote->line_items;
            if (isset($lines[0])) {
                $lines[0]['mollie_refund_id'] = $refundId;
                $creditNote->line_items = $lines;
            }
            $creditNote->refund_reason_code = RefundReasonCode::Other;
            $creditNote->refund_reason_text = 'synced from Mollie dashboard';
            $creditNote->save();

            $original->refunded_net = (int) $original->refunded_net + $netAmount;
            $original->save();

            event(new InvoiceRefunded($billable, $original, $creditNote, [
                'reason_code' => RefundReasonCode::Other,
                'reason_text' => 'synced from Mollie dashboard',
                'mollie_refund_id' => $refundId,
            ]));
        }
    }

    protected function resolveBillableFromMetadata(object $payment): ?Billable
    {
        $metadata = (array) ($payment->metadata ?? []);
        if (is_object($payment->metadata ?? null)) {
            $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
        }

        $type = (string) ($metadata['billable_type'] ?? '');
        $id = $metadata['billable_id'] ?? null;

        if ($type === '' || $id === null || ! class_exists($type)) {
            return null;
        }

        return $type::find($id);
    }

    /**
     * The first payment was received but creating the corresponding Mollie
     * subscription afterwards failed. The customer paid, so we keep the
     * invoice — but we must NOT mark the billable as Active, hydrate wallets
     * or fire SubscriptionCreated, because there is no Mollie subscription to
     * back any of that. We surface the failure via event + admin notification
     * so it can be reconciled manually.
     */
    protected function reportSubscriptionActivationFailure(
        Billable $billable,
        string $planCode,
        string $interval,
        \GraystackIT\MollieBilling\Models\BillingInvoice $invoice,
        object $payment,
        string $logMessage,
        \Throwable $error,
    ): void {
        $paymentId = (string) ($payment->id ?? '');

        Log::warning($logMessage, [
            'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
            'plan_code' => $planCode,
            'interval' => $interval,
            'payment_id' => $paymentId,
            'invoice_id' => (int) $invoice->id,
            'error' => $error->getMessage(),
        ]);

        event(new SubscriptionActivationFailed(
            $billable,
            $planCode,
            $interval,
            $paymentId,
            (int) $invoice->id,
            $error->getMessage(),
        ));

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                new AdminPlanChangeFailedNotification(
                    reason: 'Subscription activation after first payment failed — Mollie subscription was not created',
                    context: [
                        'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
                        'plan_code' => $planCode,
                        'interval' => $interval,
                        'payment_id' => $paymentId,
                        'invoice_id' => (int) $invoice->id,
                        'error' => $error->getMessage(),
                    ],
                ),
            );
        }
    }

    /**
     * Validate the first-payment coupon and pre-compute its discount, but do
     * NOT consume the redemption yet. The redemption must happen only after
     * the new Mollie subscription has been created — otherwise the coupon
     * could be permanently consumed against a subscription that never came
     * into existence.
     *
     * @return array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}|null
     */
    protected function priceFirstPaymentCoupon(
        Billable $billable,
        string $couponCode,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
    ): ?array {
        $couponCode = trim($couponCode);
        if ($couponCode === '') {
            return null;
        }

        $totalSeats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);
        $orderAmountNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $totalSeats, $addonCodes);

        try {
            $coupon = $this->couponService->validate($couponCode, $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'addonCodes' => $addonCodes,
                'orderAmountNet' => $orderAmountNet,
                'allowed_types' => [
                    \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
                    \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension,
                    \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('First-payment coupon validation failed', [
                'billable' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $discount = 0;
        if (in_array($coupon->type, [
            \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
            \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
        ], true)) {
            $discount = $this->couponService->computeRecurringDiscount($coupon, $orderAmountNet);
        }

        return [
            'coupon' => $coupon,
            'discount_net' => $discount,
            'recurring_discount_net' => $coupon->type === \GraystackIT\MollieBilling\Enums\CouponType::Recurring ? $discount : 0,
            'order_amount_net' => $orderAmountNet,
        ];
    }

    /**
     * Consume the redemption for a coupon previously validated via
     * priceFirstPaymentCoupon(). Call only after the new subscription has
     * been created.
     *
     * @param  array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount_net: int, recurring_discount_net: int, order_amount_net: int}  $priced
     */
    protected function redeemPricedFirstPaymentCoupon(
        Billable $billable,
        array $priced,
        string $planCode,
        string $interval,
        int $invoiceId,
    ): void {
        try {
            $this->couponService->redeem($priced['coupon'], $billable, [
                'planCode' => $planCode,
                'interval' => $interval,
                'orderAmountNet' => $priced['order_amount_net'],
                'discount_amount_net' => $priced['discount_net'],
                'invoice_id' => $invoiceId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('First-payment coupon redemption failed', [
                'billable' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
                'coupon_code' => (string) $priced['coupon']->code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function amountFromMolliePayment(object $payment): int
    {
        return (int) round(((float) ($payment->amount->value ?? 0)) * 100);
    }

    protected function hasRefunds(object $payment): bool
    {
        if (property_exists($payment, 'amountRefunded') && is_object($payment->amountRefunded)) {
            return ((float) ($payment->amountRefunded->value ?? 0)) > 0;
        }

        return false;
    }

    /**
     * Idempotenz-Check: existiert eine Refund-Invoice für diesen Billable, die diese mollie_refund_id
     * in einem ihrer line_items trägt?
     */
    protected function refundIdAlreadyPersisted(string $billableType, $billableId, string $refundId): bool
    {
        $refunds = BillingInvoice::query()
            ->where('billable_type', $billableType)
            ->where('billable_id', $billableId)
            ->where('invoice_kind', \GraystackIT\MollieBilling\Enums\InvoiceKind::Refund)
            ->get(['line_items']);

        foreach ($refunds as $refund) {
            foreach ((array) ($refund->line_items ?? []) as $line) {
                if (($line['mollie_refund_id'] ?? null) === $refundId) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function handleOneTimeOrderPaid(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');
        if ($productCode === '') {
            Log::warning('One-time order webhook with missing product_code', ['id' => $payment->id]);

            return;
        }

        $priceNet = $this->catalog->productPriceNet($productCode);

        // Accept both legacy single `coupon_code` and multi `coupon_codes` payloads.
        $couponCodes = (array) ($metadata['coupon_codes'] ?? []);
        if ($couponCodes === [] && ! empty($metadata['coupon_code'])) {
            $couponCodes = [(string) $metadata['coupon_code']];
        }
        $couponCodes = array_values(array_unique(array_map(
            'strtoupper',
            array_filter(array_map(fn ($c) => is_string($c) ? trim($c) : '', $couponCodes), fn (string $c) => $c !== ''),
        )));

        // Coupon resolution (best-effort): re-validate each coupon now and compute
        // its discount against the remaining net so the invoice line items match
        // the actually-charged Mollie amount. We mirror StartOneTimeOrderCheckout.
        /** @var array<int, array{coupon: \GraystackIT\MollieBilling\Models\Coupon, discount: int}> */
        $resolvedCoupons = [];
        $totalDiscountNet = 0;
        $existingCouponIds = [];
        $remainingNet = $priceNet;
        foreach ($couponCodes as $code) {
            try {
                $coupon = $this->couponService->validate($code, $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $remainingNet,
                    'existingCouponIds' => $existingCouponIds,
                    'allowed_types' => [
                        \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
                    ],
                ]);
                $discount = $this->couponService->computeRecurringDiscount($coupon, $remainingNet);
                $resolvedCoupons[] = ['coupon' => $coupon, 'discount' => $discount];
                $totalDiscountNet += $discount;
                $remainingNet = max(0, $remainingNet - $discount);
                $existingCouponIds[] = $coupon->id;
            } catch (\Throwable $e) {
                Log::warning('One-time order coupon validation failed during webhook', [
                    'product_code' => $productCode,
                    'coupon_code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $lineItems = [[
            'kind' => 'one_time_order',
            'code' => $productCode,
            'label' => $this->catalog->productName($productCode) ?? $productCode,
            'quantity' => 1,
            'unit_price' => $priceNet,
            'unit_price_net' => $priceNet,
            'total_net' => $priceNet,
        ]];

        foreach ($resolvedCoupons as $entry) {
            if ($entry['discount'] > 0) {
                $lineItems[] = [
                    'kind' => 'coupon',
                    'code' => $entry['coupon']->code,
                    'label' => 'Coupon '.$entry['coupon']->code,
                    'quantity' => 1,
                    'unit_price' => -$entry['discount'],
                    'unit_price_net' => -$entry['discount'],
                    'total_net' => -$entry['discount'],
                ];
            }
        }

        $invoice = $this->salesInvoiceService->createForPayment($payment, 'one_time_order', $lineItems, $billable);

        foreach ($resolvedCoupons as $entry) {
            try {
                $this->couponService->redeem($entry['coupon'], $billable, [
                    'productCodes' => [$productCode],
                    'orderAmountNet' => $priceNet,
                    'discount_amount_net' => $entry['discount'],
                    'invoice_id' => (int) $invoice->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('One-time order coupon redemption failed', [
                    'product_code' => $productCode,
                    'coupon_code' => $entry['coupon']->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $usageType = $this->catalog->productUsageType($productCode);
        $quantity = $this->catalog->productQuantity($productCode);

        Log::info('One-time order: checking wallet credit', [
            'product_code' => $productCode,
            'usage_type' => $usageType,
            'quantity' => $quantity,
            'billable_id' => $billable instanceof \Illuminate\Database\Eloquent\Model ? $billable->getKey() : null,
        ]);

        if ($usageType !== null && $quantity !== null && $quantity > 0) {
            $this->walletService->credit($billable, $usageType, $quantity, 'one_time_order:'.$productCode);

            // Track purchased credits separately so they survive period resets and plan changes.
            if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
                $wallet = $billable->getWallet($usageType);
                if ($wallet !== null) {
                    WalletUsageService::addPurchasedBalance($wallet, $quantity);
                }
            }

            Log::info('One-time order: wallet credited', [
                'product_code' => $productCode,
                'usage_type' => $usageType,
                'quantity' => $quantity,
            ]);
        }

        event(new OneTimeOrderCompleted($billable, $invoice, $productCode, $metadata));
        event(new PaymentSucceeded($billable, $invoice));
        $this->notifyInvoiceAvailable($billable, $invoice);
    }

    protected function handleOneTimeOrderFailed(object $payment, Billable $billable, array $metadata): void
    {
        $productCode = (string) ($metadata['product_code'] ?? '');

        event(new OneTimeOrderFailed(
            $billable,
            $productCode,
            (string) ($payment->id ?? ''),
            (string) ($payment->status ?? 'unknown'),
        ));
    }

    protected function notifyInvoiceAvailable(Billable $billable, BillingInvoice $invoice): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, new InvoiceAvailableNotification($billable, $invoice));
    }
}
