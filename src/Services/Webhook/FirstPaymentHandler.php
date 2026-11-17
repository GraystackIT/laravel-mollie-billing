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
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\ActivationRetry;
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
            // "Invoice exists" is only equivalent to "nothing left to do" when the
            // crash happened AFTER CreateSubscription — which is the case the gate's
            // comment describes, because that call is what sets Active. It is not the
            // case for the window between the invoice being committed and
            // CreateSubscription being reached: `createForPayment()` dispatches
            // InvoiceCreated synchronously, and events are the documented extension
            // seam, so an app listener with a dead mailer throws right there. The
            // re-delivery then landed here and logged its way out, forever, leaving a
            // paid invoice, a saved mandate, status New, no Mollie subscription and no
            // admin notification. CleanupOrphanedBillablesJob deliberately refuses to
            // touch a billable that holds an invoice, so nothing reconciled it.
            if (! $this->support->activationCompleted($billable)) {
                // The retry job resumes exactly this state: invoice there, subscription
                // not. It claims, then runs the same activation with the invoice it finds.
                if (ActivationRetry::resuming()) {
                    $this->support->claimForResume($billable, $paymentId);

                    try {
                        $this->activate($payment, $billable, $metadata);
                    } finally {
                        $this->support->releaseActivationClaim($billable, $paymentId);
                    }

                    return;
                }

                $this->support->reportIncompleteActivation($billable, $payment, 'first_payment');
            } else {
                Log::info('Webhook re-delivery: invoice exists for first-payment, skipping', [
                    'payment_id' => $paymentId,
                    'billable_id' => $billable->getKey(),
                ]);
            }

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
            $this->support->lockBillableRow($billable);

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

        // On a retry the invoice of this payment already exists; it is not written twice.
        $invoice = ActivationRetry::resuming()
            ? \GraystackIT\MollieBilling\Models\BillingInvoice::query()->where('mollie_payment_id', (string) ($payment->id ?? ''))->first()
            : null;

        $invoice ??= $this->firstPaymentArtifacts->persist(
            $payment,
            $billable,
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
            $this->firstPaymentArtifacts->couponLineItems($pricedCoupon),
        );

        try {
            $this->support->createSubscription($this->createSubscription, $billable, [
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

            // No PaymentSucceeded and no "your invoice is ready" yet: the activation is
            // being retried, and both are sent by completeActivation() when it lands. If
            // it never does, the customer gets the refund mail instead.
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

        // Each side effect is wrapped, the way OneTimeOrderHandler, LocalToMollieUpgradeHandler
        // and SingleChargeHandler already wrap theirs. Once the invoice exists a throw here is
        // terminal: the re-delivery's gate answers AlreadyBooked, activationCompleted() is
        // true, and it takes the quiet branch — Log::info and 200. So an app listener on
        // WalletCredited that throws on the second usage type left the customer Active and
        // paying with half their quota, no country-mismatch check, no SubscriptionCreated for
        // the app to react to, and nobody told.
        $this->support->completeActivation(
            $billable,
            $invoice,
            $this->catalog->includedUsages($planCode, $interval),
            fn () => $this->firstPaymentArtifacts->runCountryMatchCheck($billable),
        );
    }
}
