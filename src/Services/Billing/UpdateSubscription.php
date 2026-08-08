<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use Illuminate\Database\Eloquent\Model;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSubscription
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly ValidateSubscriptionChange $validator,
        private readonly ScheduleSubscriptionChange $scheduleService,
        private readonly WalletPlanChangeAdjuster $walletAdjuster,
        private readonly ProrataExecutor $prorataExecutor,
        private readonly MollieSubscriptionPatcher $subscriptionPatcher,
    ) {
    }

    public function update(Billable $billable, array|SubscriptionUpdateRequest $request): array
    {
        $dto = SubscriptionUpdateRequest::from($request);

        $this->validateApplyAt($dto);
        $this->guardPeriodNotLapsed($billable, $dto);

        // Hidden-plan targets are rejected here, before the scheduling branch:
        // scheduled changes never reach ValidateSubscriptionChange in this call,
        // so without this a forged `apply_at=end_of_period` request would be
        // happily stored and only blow up when it is applied at period end.
        // This runs against the caller's model; ScheduleSubscriptionChange::schedule()
        // repeats it on the locked+refreshed row for the immediate-path equivalence.
        if (! $dto->allowHiddenPlan) {
            $this->validator->assertPlanSelectable($billable, $dto->planCode);
        }

        if ($dto->applyAt === 'end_of_period') {
            $this->scheduleService->schedule($billable, $dto);

            return [
                'scheduledFor' => $billable->nextBillingDate()?->toIso8601String(),
            ];
        }

        /** @var Model&Billable $billable */
        // Serialised per billable with a cache lock, not with one long transaction.
        //
        // This method reaches Mollie three times — applyProrata() creates payments and posts
        // refunds, and the patcher cancels or PATCHes the subscription — and all three used
        // to sit inside a single DB::transaction together with every local write. Anything
        // throwing after the money moved therefore rolled the local half back while Mollie
        // kept its half: a refund the customer had received with no credit note and
        // `refunded_net` at 0, so the retry refunded again; a cancelled subscription with the
        // local row still saying Mollie/Active. CLAUDE.md states the invariant for the
        // activation path — "must never sit inside a transaction" — and this path violated it
        // with the additional twist that its refund key was deliberately randomised.
        //
        // So the read-and-decide part and the Mollie part run with nothing held, and only the
        // local writes are transactional (phase 3 below re-takes the row lock and re-reads,
        // because a job may have touched `subscription_meta` in between). The lock is what
        // keeps two concurrent changes to the same billable apart — the job that the row lock
        // used to serialise against still takes its own row lock inside phase 3.
        $lock = Cache::lock('billing:subscription-change:'.$billable->getKey(), 180);

        if (! $lock->get()) {
            throw new SubscriptionChangeInProgressException($billable);
        }

        try {
            return $this->runUpdate($billable, $dto);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of {@see update()}, once the change lock is held.
     *
     * Phase 1 (here) reads and decides, phase 2 talks to Mollie, phase 3 writes — see the
     * note in `update()` for why they are not one transaction.
     */
    private function runUpdate(Billable $billable, SubscriptionUpdateRequest $dto): array
    {
        /** @var Model&Billable $billable */
        $billable->refresh();

        $context = $this->buildContext($billable, $dto);

        // Centralized validation (seats, addons, wallets, Mollie readiness).
        $this->validator->validate($billable, $context);

        // Read back potentially mutated values from context.
        $newSeats = $context->newSeats;
        $newAddons = $context->newAddons;
        $newNet = $context->newNet;

        $seatsChanged = $newSeats !== $context->currentSeats;
        $addonsAdded = array_values(array_diff($newAddons, $context->currentAddons));
        $addonsRemoved = array_values(array_diff($context->currentAddons, $newAddons));

        // Coupon — validate all applied codes (stackable). NO redeem here yet:
        // the actual redemption depends on which path the update takes (sidegrade,
        // deferred prorata charge, PATCH-only, free downgrade, …) — each path has
        // a different correct value for `discount_amount_net` and a different
        // `invoice_id` on the redemption record. We collect resolved coupons here,
        // then dispatch redemptions per path below.
        $couponApplied = null;
        $couponsApplied = [];
        /** @var array<int, \GraystackIT\MollieBilling\Models\Coupon> $resolvedCoupons */
        $resolvedCoupons = [];
        /** @var array<int, int> $perCouponRecurringDiscountNet */
        $perCouponRecurringDiscountNet = [];
        $existingCouponIds = [];
        $remainingNet = $newNet;

        foreach ($dto->couponCodes as $code) {
            $coupon = $this->couponService->validate(
                $code,
                $billable,
                [
                    'planCode' => $context->newPlan,
                    'interval' => $context->newInterval,
                    'addonCodes' => $newAddons,
                    'orderAmountNet' => $remainingNet,
                    'existingCouponIds' => $existingCouponIds,
                    // Only Recurring coupons are valid on plan-change / seat-sync /
                    // addon-enable. SinglePayment is intentionally excluded — a 100%
                    // single_payment would zero the prorata charge but leave the
                    // local plan switched while Mollie's subscription still runs at
                    // the old amount. Recurring covers every legitimate use case
                    // here. For 100% single_payment use the Subscription Checkout
                    // (Mandate-Only) or a One-Time-Order purchase.
                    'allowed_types' => [\GraystackIT\MollieBilling\Enums\CouponType::Recurring],
                ],
            );
            $thisDiscount = $this->couponService->computeRecurringDiscount($coupon, $remainingNet);
            $resolvedCoupons[] = $coupon;
            $perCouponRecurringDiscountNet[] = $thisDiscount;
            $existingCouponIds[] = $coupon->id;
            $remainingNet = max(0, $remainingNet - $thisDiscount);
        }

        // The same predicate the patcher cancels on: "the target configuration bills
        // nothing at all". Asking `planChanged && isFreePlan()` here while the patcher
        // asked `isFreePlan()` alone is how the two came apart — a free plan with a
        // paid addon had its Mollie subscription cancelled by the patcher while this
        // kept source Mollie, status Active and the subscription id, so the customer
        // kept the addon and was never billed again.
        $downgradeToLocal = $context->isMollie && $context->newNet <= 0;

        // Pro-rata: ProrataExecutor handles charge/refund/sidegrade + Mollie-Subscription-PATCH.
        // For Mollie → Free, the patcher cancels the subscription.
        //
        // The composer's own verdict, not just the aggregate. `BillingPolicy::computeProrata()`
        // nets the two sides against each other, so an equal-price plan switch — a documented,
        // supported flavour ("same price, different plan → immediate") — reported 0 charge and
        // 0 credit and the executor never ran. The composer meanwhile produced a full charge
        // line for the new plan and a matching credit for the old one, and both were thrown
        // away: no invoice, no `refunded_net`, no quantity consumed. Nothing recorded that the
        // old plan line's remaining value now belongs to the new plan code — so it read as
        // fully refundable for the rest of the period, and a later refund would pay out value
        // already spent, while the next change in that period found no line for the plan it is
        // now on and credited nothing.
        $hasProrata = $context->isMollie
            && ($context->prorataChargeNet > 0
                || $context->prorataCreditNet > 0
                || $this->composerWouldBook($billable, $context));

        $prorataResult = null;
        // Whether Mollie has been dealt with by the pro-rata pipeline. `$hasProrata`
        // only says the *context* computed a pro-rata amount; the composer can still
        // produce no effective line (everything rounds to 0, refund candidates missing
        // or exhausted) and the executor then returns 'noop' without touching Mollie
        // at all. Reading the branch off `$hasProrata` reported a PATCH that never ran
        // — a monthly→yearly switch on the renewal day flipped the local interval
        // while Mollie kept billing monthly — and on a free downgrade it dropped
        // `mollie_subscription_id` for a subscription still live at Mollie, which then
        // could never be cancelled again.
        $prorataHandledByExecutor = false;
        if ($hasProrata) {
            $prorataResult = $this->applyProrata($billable, $context, $resolvedCoupons);
            $billable->refresh();

            // Charge in flight (Mollie has been asked to charge — Phase 2 webhook will finalize).
            //
            // Scoped to `deferred_charge`, because the marker is read off the meta and
            // an `in_flight` result means the id sitting there belongs to a DIFFERENT
            // change. Keying this call's `pending_plan_change` to that id made Phase 2
            // apply the other change's intent, clear the marker and drop this one
            // without a trace — no plan switch, no refund, no failure event. The
            // validator now refuses that combination up front; this keeps the write
            // honest if the two ever interleave anyway.
            $hasPendingCharge = $prorataResult['path'] === 'deferred_charge'
                && ! empty($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null);

            $prorataHandledByExecutor = match ($prorataResult['path']) {
                // The executor PATCHed Mollie itself.
                'sidegrade', 'refund' => true,
                // Phase 2 PATCHes once the charge clears — but only if a charge was
                // really created; a fully coupon-covered line set creates none.
                'deferred_charge' => $hasPendingCharge,
                // 'in_flight': another change is already waiting on a Mollie charge and
                // must not be patched over. 'noop': Mollie was not touched.
                'in_flight' => true,
                default => false,
            };

            // Only real plan switches (plan or interval) defer the local switch and surface
            // the legacy `pending_plan_change` marker that the plan-change-modal cancel-button
            // operates on. Seat/addon changes continue synchronously — they reach Mollie
            // immediately anyway, and the webhook clears the `pending_prorata_change` marker.
            if ($hasPendingCharge && ($context->planChanged || $context->intervalChanged)) {
                $meta = $billable->getBillingSubscriptionMeta();
                // Serialized via the context so every field Phase 2 reconstructs
                // (incl. allow_hidden_plan) is carried — only the locals read back
                // after validation and the stacked coupon codes are layered on top.
                $meta['pending_plan_change'] = array_merge($context->toPendingArray(), [
                    'seats' => $newSeats,
                    'addons' => array_values($newAddons),
                    'new_net' => $newNet,
                    'coupon_codes' => $dto->couponCodes,
                ]);
                $meta['prorata_pending_payment_id'] = $billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
                $billable->forceFill(['subscription_meta' => $meta])->save();

                event(new \GraystackIT\MollieBilling\Events\PlanChangePending($billable, $meta['pending_plan_change'], (string) $meta['prorata_pending_payment_id']));

                // Redemption for deferred charges happens in Phase-2 (applyPendingPlanChange)
                // so the redemption record carries the actual prorata discount + invoice id.
                return [
                    'planChanged' => false,
                    'intervalChanged' => false,
                    'seatsChanged' => false,
                    'addonsAdded' => [],
                    'addonsRemoved' => [],
                    'couponApplied' => null,
                    'prorataChargeNet' => $context->prorataChargeNet,
                    'prorataCreditNet' => 0,
                    'mollieSubscriptionPatched' => false,
                    'appliedAt' => null,
                    'pendingPaymentConfirmation' => true,
                    'scheduledFor' => null,
                    'events' => [\GraystackIT\MollieBilling\Events\PlanChangePending::class],
                ];
            }
        }

        // ── Phase 3: every local write, in one transaction ───────────────────────
        // The row lock is taken here rather than at the top: held from the top it would
        // have spanned the Mollie calls above. Re-reading matters — MutatesBillingMeta
        // writers (retry jobs, the overage sweep) may have committed a key in between,
        // and the `subscription_meta` write below replaces the whole column.
        return DB::transaction(function () use (
            $billable, $dto, $context, $resolvedCoupons, $perCouponRecurringDiscountNet,
            $prorataResult, $prorataHandledByExecutor, $downgradeToLocal,
            $newSeats, $newAddons, $newNet, $addonsAdded, $addonsRemoved, $seatsChanged,
            $couponsApplied,
        ): array {
            if ($billable instanceof Model) {
                // Scope-free: with an app-defined applyBillingScope() the scoped query
                // can match nothing, and then this looks like it worked — first()
                // quietly returns null, no row is locked, refresh() still loads fresh
                // data (it uses newQueryWithoutScopes()) and the read-modify-write below
                // runs unserialised.
                $billable->newQuery()
                    ->withoutGlobalScope(BillingScope::class)
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            // Redeem all validated coupons now — paths that did NOT defer a charge.
            // The discount amount written to each redemption is what was *actually
            // billed now*, which depends on the path:
            //   - sidegrade  : the prorata discount applied on the plan-switch invoice
            //   - refund-only: the prorata discount applied on the refund (rare)
            //   - other      : 0 (recurring coupons take effect on the next renewal
            //                   via the active_recurring_coupon marker; first-payment
            //                   coupons have no charge to attach to)
            $prorataDiscounts = $this->computeProrataDiscountsPerCoupon(
                $resolvedCoupons,
                $perCouponRecurringDiscountNet,
                $context,
            );
            $invoiceIdForRedemption = $prorataResult !== null
                ? ($prorataResult['invoice']?->id)
                : null;

            foreach ($resolvedCoupons as $i => $coupon) {
                $discount = ($invoiceIdForRedemption !== null)
                    ? ($prorataDiscounts[$i] ?? 0)
                    : 0;

                $context_redeem = [
                    'planCode' => $context->newPlan,
                    'interval' => $context->newInterval,
                    'orderAmountNet' => $newNet,
                    'discount_amount_net' => $discount,
                ];
                if ($invoiceIdForRedemption !== null) {
                    $context_redeem['invoice_id'] = (int) $invoiceIdForRedemption;
                }

                $this->couponService->redeem($coupon, $billable, $context_redeem);
                $couponsApplied[] = (string) $coupon->code;
            }
            $couponApplied = $couponsApplied !== [] ? $couponsApplied[0] : null;

            $mollieSubscriptionPatched = false;

            // Declared out here because the wallet adjuster below reads them outside the
            // `instanceof Model` block that assigns them. Left unset they arrived as
            // null, which silently turned off usage proration via
            // WalletPlanChangeAdjuster's boundaries-explicit check.
            $oldPeriodStart = null;
            $oldPeriodEnd = null;

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();

                if ($downgradeToLocal) {
                    // Whether the subscription is definitively gone at Mollie. Dropping
                    // the id on a failed cancel orphaned a live subscription that kept
                    // charging the old price with nothing able to target it any more.
                    $cancelledAtMollie = true;

                    if (! $prorataHandledByExecutor) {
                        $cancelledAtMollie = $this->subscriptionPatcher->cancelForFreeDowngrade($billable);
                        // The patcher writes `pending_subscription_cancel` on failure, so
                        // re-read before this method's own meta edits overwrite it.
                        $billable->refresh();
                        $meta = $billable->getBillingSubscriptionMeta();
                    }

                    // The same hygiene ActivateLocalSubscription performs — this is the
                    // other way onto a Local subscription, and it used to clear three
                    // keys where its sibling cleared ten. What survived was not inert:
                    //
                    // - `past_due_since` + a PastDue status that the write below never
                    //   normalised. `guardPeriodNotLapsed` exempts PastDue and a free
                    //   target prices at 0, so the downgrade went through, the source
                    //   flipped to Local and the status stayed PastDue — which
                    //   `hasAccessibleBillingSubscription()` refuses, and no renewal will
                    //   ever come to recover a Local subscription. The stale dunning clock
                    //   then auto-cancelled and expired the row.
                    // - `scheduled_change` / `scheduled_change_at`: the daily dispatcher
                    //   kept applying an upgrade the validator refuses for a Local plan,
                    //   and `apply()` re-threw without clearing the column, so it failed
                    //   every night forever.
                    // - `next_charge_date_override`: outranks the period anchor in
                    //   `nextBillingDate()`, deferring the free plan's quota recharge.
                    $meta = SubscriptionMetaHygiene::forFreshSubscription($meta);

                    if ($cancelledAtMollie) {
                        unset($meta['mollie_subscription_id']);
                    }
                } elseif ($context->isMollie && ! $prorataHandledByExecutor && ($context->planChanged || $context->intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)) {
                    // No money flow: PATCH directly via the patcher (sidegrade-style without Saldo-0 charge).
                    // Failures are queued for the RetrySubscriptionPatchJob — the local state still flips.
                    $intent = $this->buildIntent($billable, $context, $newSeats, $newAddons);
                    try {
                        $this->subscriptionPatcher->updateForIntent($billable, $intent);
                        $mollieSubscriptionPatched = true;
                    } catch (\Throwable $e) {
                        Log::warning('Mollie-Subscription PATCH failed — queued for retry', [
                            'billable' => $billable->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                        $meta['pending_subscription_patch'] = $this->queuePatchRetry($billable, $intent, $e);
                    }

                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = BillingTime::nowUtc()->toIso8601String();
                } elseif ($context->isMollie && $prorataHandledByExecutor) {
                    $mollieSubscriptionPatched = $prorataResult['path'] === 'sidegrade'
                        || $prorataResult['path'] === 'refund';
                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = BillingTime::nowUtc()->toIso8601String();
                }

                $meta['seat_count'] = $newSeats;

                // The wallet adjuster prorates the old plan's entitlement over the
                // OLD period, so capture it before the write below moves the interval
                // (and, on a downgrade to free, the period start itself).
                $oldPeriodStart = $billable->getBillingPeriodStartsAt();
                $oldPeriodEnd = $billable->nextBillingDate();

                $billable->forceFill([
                    'subscription_plan_code' => $context->newPlan,
                    'subscription_interval' => $context->newInterval,
                    'active_addon_codes' => array_values($newAddons),
                    'subscription_meta' => $meta,
                    ...($downgradeToLocal ? [
                        'subscription_source' => SubscriptionSource::Local,
                        'subscription_period_starts_at' => BillingTime::nowUtc(),
                        'subscription_ends_at' => null,
                        // Normalised, like ActivateLocalSubscription does. A PastDue or
                        // Trial status carried into a Local free plan is unrecoverable:
                        // access is refused and no renewal will ever arrive to clear it.
                        'subscription_status' => SubscriptionStatus::Active,
                        'trial_ends_at' => null,
                        // Cleared with the meta entry above — the period-end dispatcher
                        // keys off this column alone.
                        'scheduled_change_at' => null,
                    ] : []),
                ])->save();
            }

            if ($context->planChanged || $context->intervalChanged) {
                $this->walletAdjuster->adjust(
                    $billable,
                    $context->currentPlan,
                    $context->currentInterval,
                    $context->newPlan,
                    $context->newInterval,
                    $oldPeriodStart,
                    $oldPeriodEnd,
                );
            }

                return $this->dispatchEventsAndBuildResult(
                    $billable, $dto, $context, $newSeats, $newAddons, $addonsAdded, $addonsRemoved,
                    $seatsChanged, $couponApplied, $mollieSubscriptionPatched,
                );
        });
    }

    /**
     * Trampoline: build PlanChangeIntent + invoke the new ProrataExecutor pipeline.
     *
     * @param  list<\GraystackIT\MollieBilling\Models\Coupon>  $resolvedCoupons
     *         Coupons already validated in update(); we apply their discount to the
     *         prorata charge as additional negative lines (kind=coupon).
     * @return array{path: string, invoice: ?\GraystackIT\MollieBilling\Models\BillingInvoice}
     */
    /**
     * Whether the composer has anything to book even though the aggregate nets to zero.
     *
     * Only asked when the aggregate says "nothing to do", so the extra compose() is paid for
     * exactly on the change that would otherwise be dropped. `applyProrata()` composes again
     * from its own intent — the lines are not threaded through, because that intent carries
     * the resolved coupons and this one deliberately does not.
     */
    protected function composerWouldBook(Billable $billable, SubscriptionChangeContext $context): bool
    {
        $intent = $this->buildIntent($billable, $context, $context->newSeats, $context->newAddons);

        foreach (app(ProrataComposer::class)->compose($intent) as $line) {
            if ($line->amountNet !== 0) {
                return true;
            }
        }

        return false;
    }

    protected function applyProrata(Billable $billable, SubscriptionChangeContext $context, array $resolvedCoupons = []): array
    {
        $intent = $this->buildIntent(
            $billable,
            $context,
            $context->newSeats,
            $context->newAddons,
        );

        $composer = app(ProrataComposer::class);
        $lines = $composer->compose($intent);

        if ($resolvedCoupons !== []) {
            $lines = $this->applyCouponDiscountsToProrataLines($lines, $resolvedCoupons, $context);
        }

        return $this->prorataExecutor->execute($billable, $intent, $lines);
    }

    /**
     * Compute the per-coupon discount net that was actually billed on the prorata
     * charge.
     *
     * Both Recurring and SinglePayment coupons apply their discount rate directly
     * against the prorata charge net (the actual money flowing "now"). The
     * recurring coupon's ongoing effect on future renewals lives in the
     * `active_recurring_coupon` marker and is independent of this calculation.
     * Mirrors `applyCouponDiscountsToProrataLines()` and the equivalent loop in
     * `PreviewService::previewUpdate()` so redemption records line up with what
     * was billed via Mollie.
     *
     * @param  list<\GraystackIT\MollieBilling\Models\Coupon>  $resolvedCoupons
     * @param  list<int>  $perCouponRecurringDiscountNet  recurring discount per coupon (unused — kept for signature stability)
     * @return array<int, int>  per-coupon prorata discount net, indexed identically to $resolvedCoupons
     */
    private function computeProrataDiscountsPerCoupon(
        array $resolvedCoupons,
        array $perCouponRecurringDiscountNet,
        SubscriptionChangeContext $context,
    ): array {
        $out = array_fill(0, count($resolvedCoupons), 0);

        if ($context->prorataChargeNet <= 0) {
            return $out;
        }

        $remaining = $context->prorataChargeNet;

        foreach ($resolvedCoupons as $i => $coupon) {
            if ($remaining <= 0) {
                break;
            }
            if (
                $coupon->type !== \GraystackIT\MollieBilling\Enums\CouponType::Recurring
                && $coupon->type !== \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment
            ) {
                continue;
            }

            $thisDiscount = $this->couponService->computeRecurringDiscount($coupon, $remaining);
            $thisDiscount = min($thisDiscount, $remaining);

            if ($thisDiscount > 0) {
                $out[$i] = $thisDiscount;
                $remaining -= $thisDiscount;
            }
        }

        return $out;
    }

    /**
     * Append coupon discount lines (negative amounts) to the prorata line set.
     *
     * Both Recurring and SinglePayment coupons apply their discount rate directly
     * against the remaining prorata charge net. The recurring coupon's ongoing
     * effect on future renewals is independent and lives in the
     * `active_recurring_coupon` marker.
     *
     * @param  list<\GraystackIT\MollieBilling\Support\ProrataLine>  $lines
     * @param  list<\GraystackIT\MollieBilling\Models\Coupon>  $resolvedCoupons
     * @return list<\GraystackIT\MollieBilling\Support\ProrataLine>
     */
    private function applyCouponDiscountsToProrataLines(array $lines, array $resolvedCoupons, SubscriptionChangeContext $context): array
    {
        // Net prorata charge: charge-direction lines minus refund-direction lines.
        // The Mollie charge that fires collects the charge-direction lines *only* —
        // ProrataExecutor::partition() routes the refund-direction lines to separate
        // Mollie refunds (InvoiceService::createCharge() vs createRefund()), they are
        // not netted into the payment. Netting them here is nevertheless the right
        // bound for a coupon: what the change costs the customer is charge minus
        // refunds, and a discount larger than that would hand out money.
        $chargeNet = 0;
        $vatRate = 0.0;
        $periodStart = null;
        $periodEnd = null;
        $daysActive = 0;
        $daysRemaining = 0;

        foreach ($lines as $line) {
            // Direction-agnostic: refund lines already carry a negative amountNet, so
            // adding every line is the netting described above.
            $chargeNet += $line->amountNet;

            if ($line->kind === 'plan' && $line->vatRate > 0 && $vatRate === 0.0) {
                $vatRate = (float) $line->vatRate;
            }
            if ($periodStart === null) {
                $periodStart = $line->periodStart;
                $periodEnd = $line->periodEnd;
                $daysActive = $line->daysActive;
                $daysRemaining = $line->daysRemaining;
            }
        }

        if ($chargeNet <= 0 || $periodStart === null || $periodEnd === null) {
            return $lines;
        }

        $remaining = $chargeNet;
        $additions = [];

        foreach ($resolvedCoupons as $coupon) {
            if ($remaining <= 0) {
                break;
            }
            if (
                $coupon->type !== \GraystackIT\MollieBilling\Enums\CouponType::Recurring
                && $coupon->type !== \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment
            ) {
                continue;
            }

            $thisDiscount = $this->couponService->computeRecurringDiscount($coupon, $remaining);
            $thisDiscount = min($thisDiscount, $remaining);

            if ($thisDiscount <= 0) {
                continue;
            }

            $vatAmount = (int) round($thisDiscount * $vatRate / 100);
            // direction='charge' with a negative amount: the line is netted into the
            // Mollie charge total (a refund-direction would route it to createRefund,
            // which talks to the *original* invoice — wrong for a discount on the
            // *new* charge).
            $additions[] = new \GraystackIT\MollieBilling\Support\ProrataLine(
                originalInvoice: null,
                originalLineItemIndex: null,
                kind: 'coupon',
                code: (string) $coupon->code,
                label: __('billing::portal.coupon_label', ['code' => (string) $coupon->code]),
                quantity: 1,
                amountNet: -$thisDiscount,
                vatRate: $vatRate,
                amountVat: -$vatAmount,
                amountGross: -($thisDiscount + $vatAmount),
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                daysActive: $daysActive,
                daysRemaining: $daysRemaining,
                isCouponCovered: false,
                direction: 'charge',
            );
            $remaining -= $thisDiscount;
        }

        return [...$lines, ...$additions];
    }

    /**
     * @param  array<int, string>  $newAddons
     */
    private function buildIntent(Billable $billable, SubscriptionChangeContext $context, int $newSeats, array $newAddons): PlanChangeIntent
    {
        return new PlanChangeIntent(
            billable: $billable,
            currentPlan: $context->currentPlan,
            newPlan: $context->newPlan,
            currentInterval: $context->currentInterval,
            newInterval: $context->newInterval,
            currentSeats: $context->currentSeats,
            newSeats: $newSeats,
            // Real quantities, not 1. `SubscriptionAmount::net()`/`lineItems()` read
            // `getBillingAddonQuantity()` — their docblock promises apps overriding it are
            // honoured "consistently in every place where pricing is calculated" — and the
            // composer multiplies by the intent's quantity. Hardcoding 1 here priced the
            // prorata window for one unit while the recurring window billed three: the
            // preview quoted 27,00 for the addon, Mollie was asked for 9,00, and two thirds
            // of the old addon were never credited.
            currentAddons: $this->addonQuantities($billable, $context->currentAddons),
            newAddons: $this->addonQuantities($billable, array_values($newAddons)),
            forceResetStartDate: BillingPolicy::isPastDueReset($billable, $context->planChanged, $context->intervalChanged),
        );
    }

    /**
     * Map addon codes to the quantity the app actually bills them at.
     *
     * Mirrors `PreviewService::currentAddonQuantities()`, which has read the real value all
     * along — the divergence between the two is what made preview and charge disagree.
     *
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    private function addonQuantities(Billable $billable, array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $out[(string) $code] = $billable->getBillingAddonQuantity((string) $code) ?: 1;
        }

        return $out;
    }

    public function cancelScheduledChange(Billable $billable): void
    {
        $this->scheduleService->cancel($billable);
    }

    /**
     * Apply a pending plan change after the prorata payment has been confirmed.
     *
     * Called by MollieWebhookController::handleSingleChargePaid() when the
     * prorata payment succeeds. Re-validates the change (state may have changed
     * since Phase 1) and then applies it: cancel+recreate Mollie subscription,
     * update the billable, adjust wallets, redeem coupon, dispatch events.
     *
     * @param  ?\GraystackIT\MollieBilling\Models\BillingInvoice  $chargeInvoice
     *         The invoice the prorata charge was persisted to. When supplied, the
     *         coupon redemptions are linked to it (`invoice_id`) and the per-coupon
     *         `discount_amount_net` is read from the persisted prorata charge_lines
     *         so the audit record matches what was actually billed.
     *
     * @throws \Throwable If validation fails (pending stays in meta for admin review)
     */
    public function applyPendingPlanChange(Billable $billable, ?\GraystackIT\MollieBilling\Models\BillingInvoice $chargeInvoice = null): array
    {
        /** @var Model&Billable $billable */
        $meta = $billable->getBillingSubscriptionMeta();
        $pendingData = $meta['pending_plan_change'] ?? null;
        $pendingProrataChargeLines = (array) ($meta['pending_prorata_change']['charge_lines'] ?? []);

        if ($pendingData === null) {
            return [];
        }

        // The same state rule ProrataChargeHandler::APPLICABLE_STATES enforces, and for the
        // reason its comment gives: force-filling plan/interval/addon fields onto a Cancelled
        // or Expired row resurrects the plan-scoped fields the expiry pass wipes, which hands
        // `@planFeature` and the `billing.feature` middleware back to a subscription that has
        // ended. This path is the legacy twin of that handler — reached from
        // SingleChargeHandler for `type` metadata the package no longer emits — and it never
        // had the guard.
        // Phrased as the states that are *out* rather than the ones that are in: this path
        // is also reached for a billable whose status has not been written yet, which is not
        // an ended subscription and must keep working.
        if (in_array($billable->getBillingSubscriptionStatus(), [
            SubscriptionStatus::Cancelled,
            SubscriptionStatus::Expired,
        ], true)) {
            Log::warning('Pending plan change not applied — the subscription no longer runs', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'status' => $billable->getBillingSubscriptionStatus()?->value,
            ]);

            return [];
        }

        return DB::transaction(function () use ($billable, $pendingData, $pendingProrataChargeLines, $chargeInvoice): array {
            if ($billable instanceof Model) {
                // Scope-free: with an app-defined applyBillingScope() the scoped query can
                // match nothing, and then this looks like it worked — first() quietly
                // returns null, no row is locked, refresh() still loads fresh data (it uses
                // newQueryWithoutScopes()) and the read-modify-write below runs
                // unserialised. Every other lock read in the package bypasses the scope for
                // this reason.
                $billable->newQuery()
                    ->withoutGlobalScope(BillingScope::class)
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;
            $context = SubscriptionChangeContext::fromPendingArray($pendingData, $isMollie);

            // Captured before any write below moves the interval — the wallet
            // adjuster prorates the old entitlement over the OLD period.
            $oldPeriodStart = $billable->getBillingPeriodStartsAt();
            $oldPeriodEnd = $billable->nextBillingDate();

            // Re-validate: state may have changed between Phase 1 and webhook.
            $this->validator->validate($billable, $context);

            $newSeats = $context->newSeats;
            $newAddons = $context->newAddons;
            $newNet = $context->newNet;

            $currentSeats = $billable->getBillingSeatCount();
            $currentAddons = $billable->getActiveBillingAddonCodes();
            $seatsChanged = $newSeats !== $currentSeats;
            $addonsAdded = array_values(array_diff($newAddons, $currentAddons));
            $addonsRemoved = array_values(array_diff($currentAddons, $newAddons));

            $mollieSubscriptionPatched = false;

            if ($isMollie && ($context->planChanged || $context->intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)) {
                $intent = $this->buildIntent($billable, $context, $newSeats, $newAddons);
                try {
                    $this->subscriptionPatcher->updateForIntent($billable, $intent);
                    $mollieSubscriptionPatched = true;
                } catch (\Throwable $e) {
                    Log::warning('Mollie-Subscription PATCH failed in applyPendingPlanChange — queued for retry', [
                        'billable' => $billable->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['pending_subscription_patch'] = $this->queuePatchRetry($billable, $intent, $e);
                    $billable->forceFill(['subscription_meta' => $meta])->save();
                }
            }

            // Clear pending state.
            $this->clearPendingPlanChange($billable);
            $billable->refresh();

            $meta = $billable->getBillingSubscriptionMeta();
            $meta['seat_count'] = $newSeats;
            $meta['pending_amount_net'] = $newNet;
            $meta['pending_amount_recorded_at'] = BillingTime::nowUtc()->toIso8601String();

            $billable->forceFill([
                'subscription_plan_code' => $context->newPlan,
                'subscription_interval' => $context->newInterval,
                'active_addon_codes' => array_values($newAddons),
                'subscription_meta' => $meta,
            ])->save();

            // See update() — addons don't carry included usages.
            if ($context->planChanged || $context->intervalChanged) {
                $this->walletAdjuster->adjust(
                    $billable,
                    $context->currentPlan,
                    $context->currentInterval,
                    $context->newPlan,
                    $context->newInterval,
                    $oldPeriodStart,
                    $oldPeriodEnd,
                );
            }

            // Redeem coupons that were already billed in Phase-1.
            //
            // The Phase-1 prorata charge has already been persisted into
            // `pending_prorata_change.charge_lines` (kind='coupon' lines) and Mollie
            // has charged the customer the discounted amount. The redemption record
            // and (for Recurring) the `active_recurring_coupon` marker MUST be created
            // here, otherwise the customer is billed with the discount but without the
            // matching audit row / recurring marker.
            //
            // We therefore drive redemption from the Phase-1 snapshot and resolve the
            // coupon by code only — re-running `validate()` would re-check mutable
            // rules (expired, globally_exhausted, recurring_already_active, …) against
            // the *current* state, which can flip between Phase-1 and the webhook
            // arriving and would skip an already-fixed charge.
            $couponApplied = null;

            // Map: coupon code (uppercased) → discount net (positive cents) actually
            // billed via Mollie. This is the source of truth for the audit record.
            $billedDiscountByCode = [];
            foreach ($pendingProrataChargeLines as $line) {
                if (($line['kind'] ?? null) !== 'coupon') {
                    continue;
                }
                $code = strtoupper((string) ($line['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $billedDiscountByCode[$code] = abs((int) ($line['amount_net'] ?? 0));
            }

            // Drive iteration from `coupon_codes` (preserves order for stacking) and
            // fall back to legacy single `coupon_code`. If neither is present but the
            // snapshot still contains coupon lines (defensive — shouldn't happen),
            // use the snapshot codes as last resort so the audit record isn't lost.
            $pendingCodes = (array) ($pendingData['coupon_codes'] ?? []);
            if ($pendingCodes === [] && ! empty($pendingData['coupon_code'])) {
                $pendingCodes = [(string) $pendingData['coupon_code']];
            }
            if ($pendingCodes === [] && $billedDiscountByCode !== []) {
                $pendingCodes = array_keys($billedDiscountByCode);
            }

            foreach ($pendingCodes as $code) {
                $codeUpper = strtoupper((string) $code);
                $coupon = \GraystackIT\MollieBilling\Models\Coupon::query()
                    ->whereRaw('UPPER(code) = ?', [$codeUpper])
                    ->first();

                if ($coupon === null) {
                    Log::warning('Coupon redemption skipped during applyPendingPlanChange — coupon no longer exists', [
                        'billable' => $billable instanceof Model ? $billable->getKey() : null,
                        'coupon' => $code,
                    ]);

                    continue;
                }

                $discount = $billedDiscountByCode[$codeUpper] ?? 0;

                $redeemContext = [
                    'planCode' => $context->newPlan,
                    'interval' => $context->newInterval,
                    'orderAmountNet' => $newNet,
                    'discount_amount_net' => $discount,
                ];
                if ($chargeInvoice !== null) {
                    $redeemContext['invoice_id'] = (int) $chargeInvoice->id;
                }

                try {
                    $this->couponService->redeem($coupon, $billable, $redeemContext);
                    $couponApplied = (string) $coupon->code;
                } catch (\Throwable $e) {
                    // redeem() can still fail on hard limits (max_redemptions
                    // globally exhausted by another billable between Phase-1 and
                    // here). Log loudly — the customer was charged but the audit
                    // row is missing; admin intervention required.
                    Log::error('Coupon redemption failed during applyPendingPlanChange after Phase-1 charge', [
                        'billable' => $billable instanceof Model ? $billable->getKey() : null,
                        'coupon' => $code,
                        'discount_billed_net' => $discount,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $dto = SubscriptionUpdateRequest::from([
                'plan_code' => $context->newPlan,
                'interval' => $context->newInterval,
                'seats' => $newSeats,
                'addons' => $newAddons,
            ]);

            return $this->dispatchEventsAndBuildResult(
                $billable, $dto, $context, $newSeats, $newAddons, $addonsAdded, $addonsRemoved,
                $seatsChanged, $couponApplied, $mollieSubscriptionPatched,
            );
        });
    }

    /**
     * Remove pending plan change state from subscription_meta.
     *
     * Pure cleanup — does not dispatch events or send notifications.
     * The caller is responsible for side effects (events, notifications, failed-meta).
     */
    public function clearPendingPlanChange(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        unset($meta['pending_plan_change'], $meta['pending_prorata_change'], $meta['prorata_pending_payment_id']);
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * User-initiated cancel of a pending plan change. Cancels the open Mollie payment if
     * Mollie still allows it (some methods like PayPal report isCancelable=false — those
     * expire on their own), then clears the local pending state.
     *
     * Returns true if the Mollie payment was cancelled via API, false otherwise.
     *
     * Three outcomes, because "we cleared the local state" is only safe for one of them:
     *
     *  - Cancelled, or already dead at Mollie (canceled/expired/failed): clear, done.
     *  - Already **paid or authorized**: the local state is left untouched and false is
     *    returned. The money is in; the webhook still has to apply the change, and
     *    clearing `pending_plan_change` here would make ProrataChargeHandler::paid()
     *    return early — the customer charged for a plan change that never happens, with
     *    no invoice, no refund and no alert.
     *  - Still open but not cancelable, or the lookup/cancel threw: the local state is
     *    cleared (the customer asked for that) but the payment is recorded under
     *    `subscription_meta['orphaned_prorata_payment']` together with the plan-change
     *    payload, and the admins are notified. The paid path reconciles from that marker.
     */
    public function cancelPendingPlanChange(Billable $billable): bool
    {
        if (! ($billable instanceof Model)) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $paymentId = (string) ($meta['prorata_pending_payment_id'] ?? '');

        $cancelled = false;
        $orphanReason = null;

        if ($paymentId !== '') {
            try {
                $payment = \Mollie\Laravel\Facades\Mollie::send(
                    new \Mollie\Api\Http\Requests\GetPaymentRequest($paymentId),
                );

                $status = (string) ($payment->status ?? '');

                if (in_array($status, ['paid', 'authorized'], true)) {
                    Log::warning('Pending plan change cancel refused — the prorata payment has already settled', [
                        'billable' => $billable->getKey(),
                        'payment_id' => $paymentId,
                        'status' => $status,
                    ]);

                    return false;
                }

                if (($payment->isCancelable ?? false) === true) {
                    \Mollie\Laravel\Facades\Mollie::send(
                        new \Mollie\Api\Http\Requests\CancelPaymentRequest($paymentId),
                    );
                    $cancelled = true;
                } elseif (! in_array($status, ['canceled', 'expired', 'failed'], true)) {
                    $orphanReason = 'not_cancelable';
                }
            } catch (\Throwable $e) {
                Log::warning('Pending plan change cancel: Mollie payment lookup/cancel failed — clearing local state anyway', [
                    'billable' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ]);

                $orphanReason = 'lookup_failed';
            }
        }

        if ($orphanReason !== null) {
            $this->recordOrphanedProrataPayment($billable, $paymentId, $orphanReason);
        }

        $this->clearPendingPlanChange($billable);

        return $cancelled;
    }

    /**
     * Remember a prorata payment that may still settle after the local pending state was
     * cleared, so the paid path has something to reconcile against instead of returning
     * early on an empty pending state.
     *
     * Read by ProrataChargeHandler::reportOrphanedCharge(): when the payment settles
     * anyway, `pending_prorata_change` is empty, so the handler matches this marker on
     * the payment id, folds the reason and the intended change into the admin alert and
     * clears it. The refund itself stays manual — reversing a plan change the customer
     * cancelled is a product decision, not something to automate behind their back.
     */
    private function recordOrphanedProrataPayment(Billable $billable, string $paymentId, string $reason): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();

        // At most one prorata payment can be pending at a time (there is a single
        // `prorata_pending_payment_id`), so this replaces rather than accumulates.
        $meta['orphaned_prorata_payment'] = [
            'payment_id' => $paymentId,
            'reason' => $reason,
            'cancelled_at' => BillingTime::nowUtc()->toIso8601String(),
            'pending_plan_change' => $meta['pending_plan_change'] ?? null,
            'prorata_charge_lines' => $meta['pending_prorata_change']['charge_lines'] ?? null,
        ];
        $billable->forceFill(['subscription_meta' => $meta])->save();

        Log::error('Prorata payment orphaned by a user-initiated plan-change cancel — may still settle', [
            'billable' => $billable->getKey(),
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        $admins = \GraystackIT\MollieBilling\MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins !== []) {
            \Illuminate\Support\Facades\Notification::send($admins, \GraystackIT\MollieBilling\MollieBilling::resolveNotification(
                \GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification::class,
                'Plan change cancelled locally while its Mollie payment could still settle',
                [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'reason' => $reason,
                ],
            ));
        }
    }

    /**
     * Persist the retry marker for a failed Mollie PATCH *and* queue the retry.
     *
     * The marker alone is only read by the hourly SweepPendingRetriesJob; dispatching here
     * drains it promptly instead. The job is ShouldBeUnique per (billable, patch), so the
     * sweep finding the same marker later is a no-op while this chain is in flight.
     *
     * `billable_type` is normalised to the configured billable FQCN: both
     * PlanChangeIntent::fromArray() and RetrySubscriptionPatchJob resolve the row by class
     * name, which a morph-map alias from getMorphClass() would not satisfy.
     *
     * @return array<string, mixed>  the marker to store under `pending_subscription_patch`
     */
    private function queuePatchRetry(Billable $billable, PlanChangeIntent $intent, \Throwable $e): array
    {
        $intentData = [
            ...$intent->toArray(),
            'billable_type' => (string) config('mollie-billing.billable_model'),
            'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
        ];
        $firstAttemptAt = BillingTime::nowUtc()->toIso8601String();

        \GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob::dispatch($intentData, $firstAttemptAt);

        return [
            'intent' => $intentData,
            'first_attempt_at' => $firstAttemptAt,
            'last_error' => $e->getMessage(),
        ];
    }

    /**
     * Build a SubscriptionChangeContext from the current billable state and the DTO.
     */
    private function buildContext(Billable $billable, SubscriptionUpdateRequest $dto): SubscriptionChangeContext
    {
        $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
        $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $currentSeats = $billable->getBillingSeatCount();
        $currentAddons = $billable->getActiveBillingAddonCodes();

        $newPlan = $dto->planCode ?? $currentPlan;
        $newInterval = $dto->interval ?? $currentInterval;
        $planChanged = $newPlan !== $currentPlan;
        $intervalChanged = $newInterval !== $currentInterval;

        $newSeats = $dto->seats ?? max($billable->getBillingSeatCount(), $billable->getUsedBillingSeats(), $this->catalog->includedSeats($newPlan));

        $newAddons = $dto->addons !== null
            ? $this->normalizeAddonCodes($dto->addons)
            : $currentAddons;

        $newNet = SubscriptionAmount::net($this->catalog, $billable, $newPlan, $newInterval, $newSeats, $newAddons);
        $currentNet = SubscriptionAmount::net($this->catalog, $billable, $currentPlan, $currentInterval, $currentSeats, $currentAddons);

        $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;

        // Prorata calculation.
        $prorataChargeNet = 0;
        $prorataCreditNet = 0;
        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();

        $hasChanges = $planChanged || $intervalChanged || $newSeats !== $currentSeats
            || array_diff($newAddons, $currentAddons) || array_diff($currentAddons, $newAddons);

        $isPastDueReset = BillingPolicy::isPastDueReset($billable, $planChanged, $intervalChanged);

        if ($isPastDueReset) {
            // Past-Due: the current period was never paid (last charge failed).
            // Charge the full first period of the new plan and start fresh.
            // Mirrors PreviewService and ProrataComposer::composePastDueReset().
            $prorataChargeNet = $newNet;
            $prorataCreditNet = 0;
        } elseif ($hasChanges && $periodStart !== null && $periodEnd !== null) {
            $prorata = BillingPolicy::computeProrata($currentNet, $newNet, $intervalChanged, $periodStart, $periodEnd);
            $prorataChargeNet = $prorata['charge_net'];
            $prorataCreditNet = $prorata['credit_net'];
        } elseif ($hasChanges) {
            Log::warning('Prorata calculation skipped — missing period dates', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'period_start' => $periodStart?->toIso8601String(),
                'period_end' => $periodEnd?->toIso8601String(),
            ]);
        }

        return new SubscriptionChangeContext(
            currentPlan: $currentPlan,
            currentInterval: $currentInterval,
            currentSeats: $currentSeats,
            currentAddons: $currentAddons,
            currentNet: $currentNet,
            newPlan: $newPlan,
            newInterval: $newInterval,
            newSeats: $newSeats,
            newAddons: $newAddons,
            newNet: $newNet,
            planChanged: $planChanged,
            intervalChanged: $intervalChanged,
            prorataChargeNet: $prorataChargeNet,
            prorataCreditNet: $prorataCreditNet,
            isMollie: $isMollie,
            couponCode: $dto->couponCode,
            seatsExplicit: $dto->seats !== null,
            allowHiddenPlan: $dto->allowHiddenPlan,
        );
    }

    /**
     * Dispatch change events and build the standard result array.
     */
    private function dispatchEventsAndBuildResult(
        Billable $billable,
        SubscriptionUpdateRequest $dto,
        SubscriptionChangeContext $context,
        int $newSeats,
        array $newAddons,
        array $addonsAdded,
        array $addonsRemoved,
        bool $seatsChanged,
        ?string $couponApplied,
        bool $mollieSubscriptionPatched,
    ): array {
        $events = [];

        // Dispatched after the commit, never inside it. `update()` wraps the whole change —
        // including applyProrata(), which creates real Mollie payments and posts real
        // refunds — in one transaction, and events are the documented extension seam. A
        // synchronous app listener that threw therefore rolled back the credit note, the
        // `refunded_net` increment and the seat count while Mollie had already paid the
        // customer; the user retried, the composer saw `refunded_net = 0` and the refund
        // went out a second time. `DB::afterCommit()` runs immediately when no transaction
        // is open, so the direct callers are unaffected.
        $announce = function (object $event) use (&$events): void {
            $events[] = $event::class;

            DB::afterCommit(fn () => event($event));
        };

        if ($context->planChanged || $context->intervalChanged) {
            $announce(new PlanChanged($billable, $context->currentPlan ?: null, $context->newPlan, $context->newInterval));
        }

        if ($seatsChanged) {
            $announce(new SeatsChanged($billable, $context->currentSeats, $newSeats));
        }

        foreach ($addonsAdded as $code) {
            $announce(new AddonEnabled($billable, (string) $code));
        }

        foreach ($addonsRemoved as $code) {
            $announce(new AddonDisabled($billable, (string) $code));
        }

        $diff = [
            'planChanged' => $context->planChanged,
            'intervalChanged' => $context->intervalChanged,
            'seatsChanged' => $seatsChanged,
            'addonsAdded' => $addonsAdded,
            'addonsRemoved' => $addonsRemoved,
        ];

        $announce(new SubscriptionUpdated($billable, $dto->toArray(), $diff));

        return [
            'planChanged' => $context->planChanged,
            'intervalChanged' => $context->intervalChanged,
            'seatsChanged' => $seatsChanged,
            'addonsAdded' => $addonsAdded,
            'addonsRemoved' => $addonsRemoved,
            'couponApplied' => $couponApplied,
            'prorataChargeNet' => $context->prorataChargeNet,
            'prorataCreditNet' => $context->prorataCreditNet,
            'mollieSubscriptionPatched' => $mollieSubscriptionPatched,
            'appliedAt' => BillingTime::nowUtc()->toIso8601String(),
            'pendingPaymentConfirmation' => false,
            'scheduledFor' => null,
            'events' => $events,
        ];
    }

    /**
     * Normalize addon input: supports both a simple list of codes (['a', 'b'])
     * and an associative quantity map (['a' => 1, 'b' => 0]).
     *
     * @param  array<int|string, int|string>  $addons
     * @return array<int, string>
     */
    private function normalizeAddonCodes(array $addons): array
    {
        if ($addons === []) {
            return [];
        }

        // Associative format: keys are addon codes, values are quantities.
        if (! array_is_list($addons)) {
            return array_values(
                array_keys(array_filter($addons, fn ($q) => (int) $q > 0))
            );
        }

        // Simple list of addon code strings.
        return array_values($addons);
    }

    private function validateApplyAt(SubscriptionUpdateRequest $dto): void
    {
        // Internal re-entries (e.g. ScheduleSubscriptionChange::apply() at period
        // end) bypass the user-input mode check — they need to call update()
        // with apply_at=immediate even when the configured mode is EndOfPeriod.
        if ($dto->internal) {
            return;
        }

        $mode = config('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice);

        if ($dto->applyAt === 'end_of_period' && $mode === PlanChangeMode::Immediate) {
            throw new \RuntimeException('Scheduled plan changes are not allowed.');
        }

        if ($dto->applyAt !== 'end_of_period' && $mode === PlanChangeMode::EndOfPeriod) {
            throw new \RuntimeException('Immediate plan changes are not allowed.');
        }
    }

    /**
     * Reject user-initiated changes when the current billing period has already
     * lapsed without renewing.
     *
     * For a healthy subscription Mollie charges at period end and a webhook
     * advances `subscription_period_starts_at`, so `nextBillingDate()` always
     * stays in the future. When it lies in the past the subscription is stuck
     * between periods: the prorata factor collapses to 0
     * (`BillingPolicy::prorataFactor()` clamps negative remaining days), so a
     * seat/addon upgrade would prorate to a 0 charge and be applied for free.
     * We block the change until the renewal (or the past-due flow) catches up.
     *
     * Internal re-entries (scheduled apply at period end) carry `internal=true`
     * and are exempt — they intentionally run exactly when the period rolls over.
     * Past-due subscriptions are also exempt: plan/interval changes there are
     * handled by the dedicated past-due reset path (full first-period charge).
     *
     * @throws InvalidSubscriptionStateException When the period has lapsed and no renewal has occurred
     */
    private function guardPeriodNotLapsed(Billable $billable, SubscriptionUpdateRequest $dto): void
    {
        if ($dto->internal || $billable->isBillingPastDue()) {
            return;
        }

        $periodEnd = $billable->nextBillingDate();
        if ($periodEnd === null) {
            return;
        }

        // Day-granular UTC comparison mirrors BillingPolicy::prorataPeriodDays():
        // a change on the period-end day itself still has 0 remaining days and
        // prorates to ~0 legitimately — only a strictly past end date is stuck.
        if (BillingTime::nowUtc()->startOfDay()->greaterThan($periodEnd->copy()->utc()->startOfDay())) {
            throw new InvalidSubscriptionStateException(
                'Cannot modify the subscription — the current billing period ended on '
                .$periodEnd->copy()->utc()->toDateString()
                .' and has not renewed yet. Wait for the renewal to complete before changing the subscription.'
            );
        }
    }


    // ── REMOVED: cancelAndRecreateMollieSubscription, mollieCancelSubscription,
    //    cancelMollieSubscriptionForFreeDowngrade, mollieCreateSubscription,
    //    updateMollieSubscription, mollieUpdateSubscription —
    //    Logic lives in MollieSubscriptionPatcher.
    //
    // ── REMOVED: chargeProrataImmediate, refundProrataCredit, resolveChargeInfo,
    //    findSubscriptionPaymentId, prorataVat —
    //    Logic lives in ProrataComposer + ProrataExecutor + InvoiceService.


}
