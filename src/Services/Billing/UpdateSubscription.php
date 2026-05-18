<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use Illuminate\Database\Eloquent\Model;
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

        if ($dto->applyAt === 'end_of_period') {
            $this->scheduleService->schedule($billable, $dto);

            return [
                'scheduledFor' => $billable->nextBillingDate()?->toIso8601String(),
            ];
        }

        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($billable, $dto): array {
            if ($billable instanceof Model) {
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

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

            $downgradeToLocal = $context->isMollie
                && $context->planChanged
                && $this->catalog->isFreePlan($context->newPlan, $context->newInterval);

            // Pro-rata: ProrataExecutor handles charge/refund/sidegrade + Mollie-Subscription-PATCH.
            // For Mollie → Free, the patcher cancels the subscription.
            $hasProrata = ($context->prorataChargeNet > 0 || $context->prorataCreditNet > 0)
                && $context->isMollie;

            $prorataResult = null;
            if ($hasProrata) {
                $prorataResult = $this->applyProrata($billable, $context, $resolvedCoupons);
                $billable->refresh();

                // Charge in flight (Mollie has been asked to charge — Phase 2 webhook will finalize).
                $hasPendingCharge = ! empty($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null);

                // Only real plan switches (plan or interval) defer the local switch and surface
                // the legacy `pending_plan_change` marker that the plan-change-modal cancel-button
                // operates on. Seat/addon changes continue synchronously — they reach Mollie
                // immediately anyway, and the webhook clears the `pending_prorata_change` marker.
                if ($hasPendingCharge && ($context->planChanged || $context->intervalChanged)) {
                    $meta = $billable->getBillingSubscriptionMeta();
                    $meta['pending_plan_change'] = [
                        'current_plan' => $context->currentPlan,
                        'current_interval' => $context->currentInterval,
                        'current_seats' => $context->currentSeats,
                        'current_addons' => $context->currentAddons,
                        'plan_code' => $context->newPlan,
                        'interval' => $context->newInterval,
                        'seats' => $newSeats,
                        'addons' => array_values($newAddons),
                        'new_net' => $newNet,
                        'prorata_charge_net' => $context->prorataChargeNet,
                        'coupon_code' => $dto->couponCode,
                        'coupon_codes' => $dto->couponCodes,
                        'requested_at' => BillingTime::nowUtc()->toIso8601String(),
                    ];
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

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();

                if ($downgradeToLocal) {
                    if (! $hasProrata) {
                        $this->subscriptionPatcher->cancelForFreeDowngrade($billable);
                    }
                    // Free downgrade tears down the Mollie subscription — any active
                    // recurring-coupon marker no longer applies (no future charges).
                    unset(
                        $meta['mollie_subscription_id'],
                        $meta['pending_amount_net'],
                        $meta['pending_amount_recorded_at'],
                        $meta['active_recurring_coupon'],
                    );
                } elseif ($context->isMollie && ! $hasProrata && ($context->planChanged || $context->intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)) {
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
                        $meta['pending_subscription_patch'] = [
                            'intent' => $intent->toArray(),
                            'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                            'last_error' => $e->getMessage(),
                        ];
                    }

                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = BillingTime::nowUtc()->toIso8601String();
                } elseif ($context->isMollie && $hasProrata) {
                    $mollieSubscriptionPatched = true;
                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = BillingTime::nowUtc()->toIso8601String();
                }

                $meta['seat_count'] = $newSeats;

                $billable->forceFill([
                    'subscription_plan_code' => $context->newPlan,
                    'subscription_interval' => $context->newInterval,
                    'active_addon_codes' => array_values($newAddons),
                    'subscription_meta' => $meta,
                    ...($downgradeToLocal ? [
                        'subscription_source' => SubscriptionSource::Local,
                        'subscription_period_starts_at' => BillingTime::nowUtc(),
                        'subscription_ends_at' => null,
                    ] : []),
                ])->save();
            }

            if ($context->planChanged || $context->intervalChanged) {
                $this->walletAdjuster->adjust($billable, $context->currentPlan, $context->currentInterval, $context->newPlan, $context->newInterval);
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
        // The Mollie charge that will actually fire is the SUM of all charge-direction
        // line amounts (positive and negative); refunds are netted there. Coupons can
        // only meaningfully reduce that final net charge.
        $chargeNet = 0;
        $vatRate = 0.0;
        $periodStart = null;
        $periodEnd = null;
        $daysActive = 0;
        $daysRemaining = 0;

        foreach ($lines as $line) {
            if ($line->direction === 'charge') {
                $chargeNet += $line->amountNet;
            } else {
                // Refund-direction lines are negative against the customer's invoice
                // already; for the purposes of "what is being charged now" we net them.
                $chargeNet += $line->amountNet;
            }
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
            currentAddons: array_fill_keys($context->currentAddons, 1),
            newAddons: array_fill_keys(array_values($newAddons), 1),
            forceResetStartDate: $billable->isBillingPastDue()
                && ($context->planChanged || $context->intervalChanged),
        );
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

        return DB::transaction(function () use ($billable, $pendingData, $pendingProrataChargeLines, $chargeInvoice): array {
            if ($billable instanceof Model) {
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;
            $context = SubscriptionChangeContext::fromPendingArray($pendingData, $isMollie);

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
                    $meta['pending_subscription_patch'] = [
                        'intent' => $intent->toArray(),
                        'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                        'last_error' => $e->getMessage(),
                    ];
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
     * User-initiated cancel of a pending plan change. Cancels the open Mollie
     * payment if Mollie still allows it (some methods like paypal report
     * isCancelable=false — those expire on their own), then clears the local
     * pending state.
     *
     * Returns true if the Mollie payment was cancelled via API, false if it
     * could not be cancelled (already final, not cancelable, or not found).
     * Local state is cleared in either case.
     */
    public function cancelPendingPlanChange(Billable $billable): bool
    {
        if (! ($billable instanceof Model)) {
            return false;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $paymentId = (string) ($meta['prorata_pending_payment_id'] ?? '');

        $cancelled = false;
        if ($paymentId !== '') {
            try {
                $payment = \Mollie\Laravel\Facades\Mollie::send(
                    new \Mollie\Api\Http\Requests\GetPaymentRequest($paymentId),
                );

                if (($payment->isCancelable ?? false) === true) {
                    \Mollie\Laravel\Facades\Mollie::send(
                        new \Mollie\Api\Http\Requests\CancelPaymentRequest($paymentId),
                    );
                    $cancelled = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Pending plan change cancel: Mollie payment lookup/cancel failed — clearing local state anyway', [
                    'billable' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearPendingPlanChange($billable);

        return $cancelled;
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

        $isPastDueReset = $billable->isBillingPastDue() && ($planChanged || $intervalChanged);

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

        if ($context->planChanged || $context->intervalChanged) {
            event(new PlanChanged($billable, $context->currentPlan ?: null, $context->newPlan, $context->newInterval));
            $events[] = PlanChanged::class;
        }

        if ($seatsChanged) {
            event(new SeatsChanged($billable, $context->currentSeats, $newSeats));
            $events[] = SeatsChanged::class;
        }

        foreach ($addonsAdded as $code) {
            event(new AddonEnabled($billable, (string) $code));
            $events[] = AddonEnabled::class;
        }

        foreach ($addonsRemoved as $code) {
            event(new AddonDisabled($billable, (string) $code));
            $events[] = AddonDisabled::class;
        }

        $diff = [
            'planChanged' => $context->planChanged,
            'intervalChanged' => $context->intervalChanged,
            'seatsChanged' => $seatsChanged,
            'addonsAdded' => $addonsAdded,
            'addonsRemoved' => $addonsRemoved,
        ];

        event(new SubscriptionUpdated($billable, $dto->toArray(), $diff));
        $events[] = SubscriptionUpdated::class;

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


    // ── REMOVED: cancelAndRecreateMollieSubscription, mollieCancelSubscription,
    //    cancelMollieSubscriptionForFreeDowngrade, mollieCreateSubscription,
    //    updateMollieSubscription, mollieUpdateSubscription —
    //    Logic lives in MollieSubscriptionPatcher.
    //
    // ── REMOVED: chargeProrataImmediate, refundProrataCredit, resolveChargeInfo,
    //    findSubscriptionPaymentId, prorataVat —
    //    Logic lives in ProrataComposer + ProrataExecutor + InvoiceService.


}
