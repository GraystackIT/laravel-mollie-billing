<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FirstPaymentHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly SubscriptionCatalogInterface $catalog,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(object $payment, Billable $billable, array $metadata): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        if ((string) ($metadata['plan_code'] ?? '') === '') {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');

        // Two phases, on purpose.
        //
        // The gate is short and transactional: it takes the row lock, runs the two
        // guards and commits a durable claim. reserve() only serializes deliveries of
        // the SAME payment id, so two different first payments (two checkout tabs)
        // would otherwise both pass hasAccessibleBillingSubscription(), both write an
        // invoice (different mollie_payment_id, so the unique index does not help) and
        // both create a Mollie subscription, the second forceFill hiding the first
        // one's id.
        //
        // The activation itself runs OUTSIDE any transaction. It used to sit inside
        // this one, which meant the Mollie CreateSubscription call did too — and
        // anything that threw after it (a coupon redemption, an app listener, the
        // after-checkout hook) rolled back `mollie_subscription_id` and the invoice
        // while the subscription existed at Mollie. The re-delivery then found a clean
        // slate and created a SECOND Mollie subscription, charging the customer twice
        // every period. It also held the row lock across arbitrary app code.
        $gate = $this->openGate($payment, $billable, $paymentId);

        if ($gate === ActivationGate::AlreadyBooked) {
            Log::info('Webhook re-delivery: invoice exists for first-payment, skipping', [
                'payment_id' => $paymentId,
                'billable_id' => $billable->getKey(),
            ]);

            return;
        }

        if ($gate === ActivationGate::Duplicate) {
            Log::warning('Duplicate first-payment received — billable already has an active subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);

            DuplicatePaymentReceived::dispatch($billable, $paymentId);

            return;
        }

        try {
            $this->activate($payment, $billable, $metadata);
        } finally {
            // Released on both paths: a throw here leaves real state behind at Mollie
            // and locally, so the re-delivery must be able to pass the gate at once
            // rather than waiting out the claim's TTL.
            $this->support->releaseActivationClaim($billable, $paymentId);
        }
    }

    /**
     * Decide — under the row lock, in its own short transaction — whether this
     * delivery may activate, and claim the activation if so.
     *
     * The invoice guard runs before the duplicate guard: after a crash mid-activation
     * the billable is both Active *and* holds this payment's invoice, and that is a
     * re-delivery to swallow silently, not a duplicate payment to report to the app.
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

            // Throws ActivationClaimHeldException when a different payment's claim is
            // still fresh; the controller answers 503 so Mollie re-delivers.
            $this->support->claimActivation($billable, $paymentId);

            return ActivationGate::Proceed;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function activate(object $payment, Billable $billable, array $metadata): void
    {
        /** @var Model&Billable $billable */
        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        // Priced BEFORE the invoice is persisted: the checkout charged the
        // discounted gross, and the invoice header is derived from its line items.
        $pricedCoupon = $this->firstPaymentCouponPricing->price(
            $billable,
            (string) ($metadata['coupon_code'] ?? ''),
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
        );
        $recurringDiscountNet = $pricedCoupon['recurring_discount_net'] ?? 0;

        $invoice = $this->firstPaymentArtifacts->persist(
            $payment,
            $billable,
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
            $this->firstPaymentArtifacts->couponLineItems($pricedCoupon),
        );

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
                'CreateSubscription during webhook failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->support->notifyInvoiceAvailable($billable, $invoice);

            return;
        }

        // A TrialExtension coupon has no business here: this payment bought a paid
        // period, so there is no trial for it to extend. Redeeming it anyway would
        // put `trial_ends_at` days into the future on a paying subscriber AND — via
        // extendBillingTrialUntil()'s Mollie PATCH — move the next charge to that
        // date, charging them again within the week. It is priced (the pricing
        // service serves the trial path too) but not applied.
        if ($pricedCoupon !== null && $pricedCoupon['coupon']->type === CouponType::TrialExtension) {
            Log::warning('First-payment coupon is a trial extension on a paid first payment — not redeemed', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $payment->id ?? null,
                'coupon_code' => (string) $pricedCoupon['coupon']->code,
            ]);
            $pricedCoupon = null;
        }

        if ($pricedCoupon !== null) {
            $this->firstPaymentCouponPricing->redeem(
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

        foreach ($this->catalog->includedUsages($planCode, $interval) as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        $this->firstPaymentArtifacts->runCountryMatchCheck($billable);

        event(new SubscriptionCreated($billable, $planCode, $interval));
        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }
}
