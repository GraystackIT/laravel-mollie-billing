<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\PaymentSucceeded;
use GraystackIT\MollieBilling\Events\SubscriptionUpgradedFromLocal;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
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
        protected readonly PlanWaiver $planWaiver,
        protected readonly CouponService $couponService,
        protected readonly SubscriptionCatalogInterface $catalog,
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
            // A purchase of extras that was refused and refunded is finished business, not
            // an activation that died half-way: there is no subscription to complete. The
            // re-delivery only repeats what may still be open — a refund that was never
            // posted, an announcement that was never sent — and each of those happens once.
            $booked = \GraystackIT\MollieBilling\Models\BillingInvoice::query()
                ->where('mollie_payment_id', $paymentId)
                ->first();

            if (GrantExtrasPurchaseRefund::isRefusedPurchase($booked)) {
                app(GrantExtrasPurchaseRefund::class)->settle($payment, $billable, $metadata);

                return;
            }

            // Likewise a duplicate upgrade payment that was booked to be returned.
            if (app(UnwantedPaymentRefund::class)->resume($payment, $billable)) {
                return;
            }

            // See FirstPaymentHandler: an invoice without a finished activation means the
            // run died between the two, which is worth telling someone about.
            if (! $this->support->activationCompleted($billable)) {
                // See FirstPaymentHandler: the retry job resumes this state.
                if (\GraystackIT\MollieBilling\Support\ActivationRetry::resuming()) {
                    if (! $this->support->claimForResume($billable, $paymentId)) {
                        return;
                    }

                    try {
                        $this->activate($payment, $billable, $planCode, $interval, $addonCodes, $extraSeats, $metadata);
                    } finally {
                        $this->support->releaseActivationClaim($billable, $paymentId);
                    }

                    return;
                }

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
            Log::warning('Duplicate local-to-Mollie upgrade payment — billable already runs a Mollie subscription; booking and refunding it', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
            ]);

            // Booked and returned in full, then DuplicatePaymentReceived (see FirstPaymentHandler).
            $this->support->settleDuplicatePayment($payment, $billable);

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
     * The duplicate condition is "a Mollie subscription is running": an upgrade that
     * already ran moved the billable to `SubscriptionSource::Mollie`, so a sibling
     * payment arriving afterwards is booked and refunded as a duplicate. The invoice guard
     * runs first for the same reason as in FirstPaymentHandler — after a crash
     * mid-activation the billable is both Mollie-source and holds this payment's
     * invoice, which is a re-delivery to swallow, not a duplicate to refund.
     */
    protected function openGate(object $payment, Billable $billable, string $paymentId): ActivationGate
    {
        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($payment, $billable, $paymentId): ActivationGate {
            $this->support->lockBillableRow($billable);

            if ($this->support->invoiceAlreadyExistsForPayment($payment)) {
                return ActivationGate::AlreadyBooked;
            }

            // "Already upgraded" is a running Mollie subscription — not merely "no longer
            // Local". The test used to be the latter, and a Local subscription can stop
            // being Local without any upgrade having happened: a gift that ran out and
            // was expired by the nightly pass, or one an operator revoked, leaves the
            // source at None. With SEPA the payment for the extras settles days after the
            // purchase was started, found that state, and was reported as a duplicate —
            // money collected, no invoice, no subscription, and only an event to show for
            // it. Such a payment proceeds to `activate()`, which books an ordinary upgrade
            // and refunds a purchase that was priced on a gift (GrantExtrasPurchaseRefund).
            if ($billable->getBillingSubscriptionSource() === \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie->value
                && $billable->hasAccessibleBillingSubscription()
            ) {
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

        // The tenant agreed to "the extras, on top of a plan that stays free". If the gift
        // is gone by the time the payment arrives — run out, revoked, expired by the
        // nightly pass — there is nothing to keep, and carrying the purchase out would
        // create a subscription at the full price that nobody agreed to. The payment is
        // booked, returned in full, and nothing about the billable changes. This used to
        // be booked as a regular upgrade (and before that, dropped as a "duplicate").
        if (($metadata['keep_grant'] ?? false) === true) {
            $grantRefund = app(GrantExtrasPurchaseRefund::class);

            if (! $grantRefund->grantStillRuns($billable)) {
                $grantRefund->settle($payment, $billable, $metadata);

                return;
            }
        }

        $oldPlan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $oldInterval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        // The Local period the customer is upgrading out of. CreateSubscription
        // rewrites the period below, so the wallet adjuster has to be handed these
        // rather than re-deriving them from a model that already moved on.
        $oldPeriodStart = $billable->getBillingPeriodStartsAt();
        $oldPeriodEnd = $billable->nextBillingDate();

        // The tenant was given this plan and is buying paid extras on top: the rest of
        // the grant becomes a plan waiver on the Mollie subscription. Written BEFORE the
        // invoice and the subscription, because both are priced from it — the invoice
        // carries the waived plan as a discount line, and CreateSubscription reads the
        // marker to set the recurring amount (and to defer the charge when it covers
        // everything). See PlanWaiver.
        $waiver = ($metadata['keep_grant'] ?? false) === true
            ? $this->carryGrantOver($billable, $planCode, $interval, $addonCodes, $extraSeats, $metadata)
            : null;

        // Priced BEFORE the invoice is persisted: the upgrade charged the
        // discounted gross, and the invoice header is derived from its line items.
        // A kept grant and a coupon do not combine — the waiver holds the one
        // recurring-discount slot — so the coupon is not looked at then.
        $pricedCoupon = $waiver === null
            ? $this->firstPaymentCouponPricing->afterPayment()->price(
                $billable,
                (string) ($metadata['coupon_code'] ?? ''),
                $planCode,
                $interval,
                $addonCodes,
                $extraSeats,
            )
            : null;
        $recurringDiscountNet = $waiver !== null
            ? $waiver['discount_net']
            : ($pricedCoupon['recurring_discount_net'] ?? 0);

        // On a retry the invoice of this payment already exists; it is not written twice.
        $invoice = \GraystackIT\MollieBilling\Support\ActivationRetry::resuming()
            ? \GraystackIT\MollieBilling\Models\BillingInvoice::query()->where('mollie_payment_id', (string) ($payment->id ?? ''))->first()
            : null;

        $invoice ??= $this->firstPaymentArtifacts->persist(
            $payment,
            $billable,
            $planCode,
            $interval,
            $addonCodes,
            $extraSeats,
            $waiver !== null
                ? $this->waiverLineItems($waiver)
                : $this->firstPaymentArtifacts->couponLineItems($pricedCoupon),
        );

        try {
            $this->support->createSubscription($this->createSubscription, $billable, [
                'plan_code' => $planCode,
                'interval' => $interval,
                'addon_codes' => $addonCodes,
                'extra_seats' => $extraSeats,
                'recurring_discount_net' => $recurringDiscountNet,
                'mandate_id' => $billable->mollie_mandate_id,
                'activating_payment_id' => (string) ($payment->id ?? ''),
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

            // Announced when the retry lands — see FirstPaymentHandler.
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

        if ($waiver !== null) {
            $this->finishGrantCarryOver($billable, $waiver, (int) $invoice->id);
        }

        // A kept grant stays on its plan: there is no old plan's quota to settle against
        // a new one, and the wallets already hold this plan's allowance.
        if ($oldPlan !== '' && $waiver === null) {
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

        // Each step isolated. Past this point the invoice exists and the subscription is
        // live at Mollie, so the re-delivery is swallowed by the invoice guard — anything
        // that throws here is lost work nobody retries. Events are the documented extension
        // seam, and a throwing app listener used to take `runAfterCheckout()` down with it:
        // the customer had paid and been upgraded, but the app never learned to admit them.
        $this->support->safely(
            fn () => event(new SubscriptionUpgradedFromLocal($billable, $oldPlan, $oldInterval, $planCode, $interval)),
            'local_upgrade.upgraded_event',
            $billable,
        );

        $this->support->safely(
            fn () => event(new PaymentSucceeded($billable, $invoice)),
            'local_upgrade.payment_succeeded_event',
            $billable,
        );

        $this->support->safely(
            fn () => $this->support->notifyInvoiceAvailable($billable, $invoice),
            'local_upgrade.invoice_notification',
            $billable,
        );

        $this->support->safely(
            fn () => MollieBilling::runAfterCheckout($billable, true),
            'local_upgrade.after_checkout',
            $billable,
        );
    }

    /**
     * Turn the running access grant into a plan waiver, and say what it takes off this
     * charge.
     *
     * Returns null — and the upgrade proceeds as an ordinary paid one — when there is no
     * running grant left to carry: the payment asked to keep something that has since
     * ended or been revoked. Nothing is waived that the grant no longer covers.
     *
     * @param  array<int, string>  $addonCodes
     * @param  array<string, mixed>  $metadata
     * @return array{discount_net: int, order_amount_net: int, code: string, until: \Carbon\CarbonInterface, applies_to: list<string>}|null
     */
    protected function carryGrantOver(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes,
        int $extraSeats,
        array $metadata,
    ): ?array {
        /** @var Model&Billable $billable */
        $grantEndsAt = $billable->getBillingSubscriptionEndsAt();

        if ($grantEndsAt === null || ! $grantEndsAt->isFuture()) {
            Log::warning('Upgrade asked to keep a grant that is no longer running — charged as a regular upgrade', [
                'billable_id' => $billable->getKey(),
            ]);

            return null;
        }

        // The grant this waiver continues: the newest live full grant. Its coupon is what
        // keeps the marker redeemable and says what happens when the free time ends.
        $redemption = CouponRedemption::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->whereNull('revoked_at')
            ->where('grant_applied_snapshot->mode', 'full')
            ->latest('id')
            ->first();

        $coupon = $redemption?->coupon;

        if ($coupon === null) {
            Log::warning('Upgrade asked to keep a grant, but no live grant redemption was found — charged as a regular upgrade', [
                'billable_id' => $billable->getKey(),
            ]);

            return null;
        }

        $granted = array_values(array_intersect(
            array_map('strval', (array) ($metadata['granted_addon_codes'] ?? [])),
            $addonCodes,
        ));

        $seats = $this->catalog->includedSeats($planCode) + max(0, $extraSeats);

        // The seat count the waiver is measured at has to be the one being bought, not
        // the Local one still on the row.
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['seat_count'] = $seats;
        $billable->forceFill(['subscription_meta' => $meta])->save();

        $this->planWaiver->grant(
            $billable,
            $coupon,
            $planCode,
            $interval,
            $granted,
            $grantEndsAt,
            null,
            // An operator's per-tenant choice wins over the coupon's default.
            PlanWaiver::normalizeOnExpiry($meta['grant_on_expiry'] ?? $coupon->grant_on_expiry),
            (int) $redemption->id,
        );

        $billable->refresh();

        $orderNet = SubscriptionAmount::net($this->catalog, $billable, $planCode, $interval, $seats, $addonCodes);
        $discount = $this->couponService->computeMarkerDiscount($billable, $orderNet, [
            'plan_code' => $planCode,
            'interval' => $interval,
            'addon_codes' => $addonCodes,
            'seats' => $seats,
        ]);

        return [
            'discount_net' => $discount,
            'order_amount_net' => $orderNet,
            'code' => (string) $coupon->code,
            'until' => $grantEndsAt,
            'applies_to' => PlanWaiver::appliesTo($this->planWaiver->marker($billable) ?? [], $addonCodes),
        ];
    }

    /**
     * @param  array{discount_net: int, order_amount_net: int, code: string, until: \Carbon\CarbonInterface}  $waiver
     * @return array<int, array<string, mixed>>
     */
    protected function waiverLineItems(array $waiver): array
    {
        if ($waiver['discount_net'] <= 0) {
            return [];
        }

        return [[
            'kind' => 'coupon',
            'code' => $waiver['code'],
            'label' => 'Plan free until '.$waiver['until']->copy()->utc()->toDateString(),
            'quantity' => 1,
            'unit_price' => -$waiver['discount_net'],
            'unit_price_net' => -$waiver['discount_net'],
            'total_net' => -$waiver['discount_net'],
            // Which lines this takes off — a later prorata credit reads it, so the free
            // plan is not credited and the paid extras are credited in full.
            'applies_to' => $waiver['applies_to'] ?? ['plan'],
        ]];
    }

    /**
     * After the subscription exists: record this first waived period, and end the
     * subscription at the end of it when the gift does not reach the next charge and was
     * given to end rather than to turn into a paid plan.
     *
     * @param  array{discount_net: int, order_amount_net: int, code: string, until: \Carbon\CarbonInterface}  $waiver
     */
    protected function finishGrantCarryOver(Billable $billable, array $waiver, int $invoiceId): void
    {
        /** @var Model&Billable $billable */
        $this->support->safely(
            fn () => $this->couponService->redeemRecurringCouponForRenewal(
                $billable->refresh(),
                $waiver['order_amount_net'],
                $invoiceId,
            ),
            'local_upgrade.waiver_redemption',
            $billable,
        );

        $this->support->safely(
            function () use ($billable): void {
                $billable->refresh();
                $nextCharge = $billable->nextBillingDate();

                if ($nextCharge !== null && $this->planWaiver->endsSubscription($billable, $nextCharge)) {
                    app(CancelSubscription::class)->handle($billable, false, 'grant_expired');

                    return;
                }

                // Given to continue as a paid plan, and over before the first renewal:
                // that renewal is the first charge AFTER the gift. The subscription was
                // just created at the extras-only amount, and the renewal handler only
                // returns Mollie to the full price one renewal too late — the plan was
                // collected a period short. Same step that handler takes when a waiver
                // will not cover the next charge: full price, marker cleared.
                if ($nextCharge !== null && $this->couponService->markerExpired($billable, $nextCharge)) {
                    $this->planWaiver->remove($billable);
                    $this->planWaiver->syncMollieOrQueue($billable->refresh());
                }
            },
            'local_upgrade.waiver_expiry',
            $billable,
        );
    }
}
