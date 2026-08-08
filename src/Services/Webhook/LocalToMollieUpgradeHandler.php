<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocalToMollieUpgradeHandler
{
    public function __construct(
        protected readonly WebhookSupport $support,
        protected readonly FirstPaymentArtifacts $firstPaymentArtifacts,
        protected readonly FirstPaymentCouponPricing $firstPaymentCouponPricing,
        protected readonly CreateSubscription $createSubscription,
        protected readonly WalletPlanChangeAdjuster $walletAdjuster,
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

        $planCode = (string) ($metadata['plan_code'] ?? '');
        $interval = (string) ($metadata['interval'] ?? 'monthly');
        $addonCodes = (array) ($metadata['addon_codes'] ?? []);
        $extraSeats = (int) ($metadata['extra_seats'] ?? 0);

        if ($planCode === '') {
            return;
        }

        $paymentId = (string) ($payment->id ?? '');

        // This is an activation path, so it gets the same two-phase gate as
        // FirstPaymentHandler and MandateOnlyPaymentHandler — it used to have only
        // the per-payment invoice guard, which is no protection at all against a
        // SECOND upgrade payment. `UpgradeLocalToMollie` mints a fresh payment on
        // every attempt and cancels none (its Local-source check runs at checkout
        // start, not at settlement), so two tabs or a back-button re-submit produced
        // two paid payments. Payment A activated and created `sub_A`; payment B then
        // passed the invoice guard (different id), wrote a second invoice and created
        // `sub_B`, whose id overwrote `sub_A`'s in meta — leaving `sub_A` charging the
        // customer every period with nothing locally pointing at it. Sequentially,
        // without any concurrency needed.
        $gate = $this->openGate($payment, $billable, $paymentId);

        if ($gate === ActivationGate::AlreadyBooked) {
            // See FirstPaymentHandler: an invoice without a finished activation means the
            // run died between the two, which is worth telling someone about.
            if (! $this->support->activationCompleted($billable)) {
                $this->support->reportIncompleteActivation($billable, $payment, 'local_upgrade');
            } else {
                Log::info('Webhook re-delivery: invoice exists for local-to-Mollie upgrade, skipping', [
                    'payment_id' => $paymentId,
                    'billable_id' => $billable->getKey(),
                ]);
            }

            return;
        }

        if ($gate === ActivationGate::Duplicate) {
            Log::warning('Duplicate local-to-Mollie upgrade payment — billable is no longer on a Local subscription', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);

            DuplicatePaymentReceived::dispatch($billable, $paymentId);

            return;
        }

        try {
            $this->activate($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $metadata);
        } finally {
            $this->support->releaseActivationClaim($billable, $paymentId);
        }
    }

    /**
     * Decide — under the row lock, in its own short transaction — whether this
     * delivery may upgrade, and claim the activation if so.
     *
     * The duplicate condition is "no longer Local": an upgrade that already ran
     * moved the billable to `SubscriptionSource::Mollie`, so a sibling payment
     * arriving afterwards has nothing left to do but be reported. The invoice guard
     * runs first for the same reason as in FirstPaymentHandler — after a crash
     * mid-activation the billable is both Mollie-source and holds this payment's
     * invoice, which is a re-delivery to swallow, not a duplicate to report.
     */
    protected function openGate(object $payment, Billable $billable, string $paymentId): ActivationGate
    {
        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($payment, $billable, $paymentId): ActivationGate {
            $this->support->lockBillableRow($billable);

            if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
                return ActivationGate::AlreadyBooked;
            }

            if (! $billable->isLocalBillingSubscription()) {
                return ActivationGate::Duplicate;
            }

            $this->support->claimActivation($billable, $paymentId);

            return ActivationGate::Proceed;
        });
    }

    /**
     * @param  array<int, string>  $addonCodes
     * @param  array<string, mixed>  $metadata
     */
    protected function activate(
        object $payment,
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        array $metadata,
    ): void {
        /** @var Model&Billable $billable */
        $oldPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $oldInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        // The Local period the customer is upgrading out of. CreateSubscription
        // rewrites the period below, so the wallet adjuster has to be handed these
        // rather than re-deriving them from a model that already moved on.
        $oldPeriodStart = $billable->getBillingPeriodStartsAt();
        $oldPeriodEnd = $billable->nextBillingDate();

        // Priced BEFORE the invoice is persisted: the upgrade charged the
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
                'CreateSubscription during local upgrade failed',
                $e,
            );
            event(new PaymentSucceeded($billable, $invoice));
            $this->support->notifyInvoiceAvailable($billable, $invoice);

            return;
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

        if ($oldPlan !== '') {
            // Wrapped like its prorata sibling: past this point the invoice exists, so a
            // throw would be swallowed by the re-delivery's invoice guard and the wallets
            // would stay on the old plan's quota forever.
            try {
                $this->walletAdjuster->adjust(
                    $billable,
                    $oldPlan,
                    $oldInterval,
                    $planCode,
                    $interval,
                    $oldPeriodStart,
                    $oldPeriodEnd,
                );
            } catch (\Throwable $e) {
                Log::error('Wallet adjust during the local-to-Mollie upgrade failed', [
                    'billable_id' => $billable->getKey(),
                    'old_plan' => $oldPlan,
                    'new_plan' => $planCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->firstPaymentArtifacts->runCountryMatchCheck($billable);

        event(new SubscriptionUpgradedFromLocal(
            $billable,
            $oldPlan,
            $oldInterval,
            $planCode,
            $interval,
        ));
        event(new PaymentSucceeded($billable, $invoice));

        $this->support->notifyInvoiceAvailable($billable, $invoice);

        MollieBilling::runAfterCheckout($billable, true);
    }
}
