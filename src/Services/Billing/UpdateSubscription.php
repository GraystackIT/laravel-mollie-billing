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

            // Coupon — validate only; redemption is deferred for pending upgrades.
            $couponApplied = null;
            $couponDiscountNet = 0;

            if ($dto->couponCode !== null && $dto->couponCode !== '') {
                $coupon = $this->couponService->validate(
                    $dto->couponCode,
                    $billable,
                    [
                        'planCode' => $context->newPlan,
                        'interval' => $context->newInterval,
                        'addonCodes' => $newAddons,
                        'orderAmountNet' => $newNet,
                    ],
                );
                $couponDiscountNet = $this->couponService->computeRecurringDiscount($coupon, $newNet);
            }

            // Redeem coupon now.
            if ($dto->couponCode !== null && $dto->couponCode !== '' && isset($coupon)) {
                $this->couponService->redeem($coupon, $billable, [
                    'planCode' => $context->newPlan,
                    'interval' => $context->newInterval,
                    'orderAmountNet' => $newNet,
                    'discount_amount_net' => $couponDiscountNet,
                ]);
                $couponApplied = (string) $coupon->code;
            }

            $downgradeToLocal = $context->isMollie
                && $context->planChanged
                && $this->catalog->isFreePlan($context->newPlan, $context->newInterval);

            // Pro-rata: ProrataExecutor handles charge/refund/sidegrade + Mollie-Subscription-PATCH.
            // For Mollie → Free, the patcher cancels the subscription.
            $hasProrata = ($context->prorataChargeNet > 0 || $context->prorataCreditNet > 0)
                && $context->isMollie;

            if ($hasProrata) {
                $this->applyProrata($billable, $context);
                $billable->refresh();

                // Charge in flight (Mollie has been asked to charge — Phase 2 webhook will finalize).
                $hasPendingCharge = ! empty($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null);

                // Only echte Plan-Wechsel (plan or interval) defer the local switch and surface
                // the legacy `pending_plan_change` marker that the plan-change-modal cancel-button
                // operates on. Sitz-/Addon-Änderungen laufen synchron weiter — sie kommen ohnehin
                // sofort in Mollie an und der Webhook räumt das `pending_prorata_change`-Marker auf.
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
                        'requested_at' => BillingTime::nowUtc()->toIso8601String(),
                    ];
                    $meta['prorata_pending_payment_id'] = $billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];
                    $billable->forceFill(['subscription_meta' => $meta])->save();

                    event(new \GraystackIT\MollieBilling\Events\PlanChangePending($billable, $meta['pending_plan_change'], (string) $meta['prorata_pending_payment_id']));

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

            $mollieSubscriptionPatched = false;

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();

                if ($downgradeToLocal) {
                    if (! $hasProrata) {
                        $this->subscriptionPatcher->cancelForFreeDowngrade($billable);
                    }
                    unset($meta['mollie_subscription_id'], $meta['pending_amount_net'], $meta['pending_amount_recorded_at']);
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
     */
    protected function applyProrata(Billable $billable, SubscriptionChangeContext $context): void
    {
        $intent = $this->buildIntent(
            $billable,
            $context,
            $context->newSeats,
            $context->newAddons,
        );

        $composer = app(ProrataComposer::class);
        $lines = $composer->compose($intent);

        $this->prorataExecutor->execute($billable, $intent, $lines);
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
     * @throws \Throwable If validation fails (pending stays in meta for admin review)
     */
    public function applyPendingPlanChange(Billable $billable): array
    {
        /** @var Model&Billable $billable */
        $meta = $billable->getBillingSubscriptionMeta();
        $pendingData = $meta['pending_plan_change'] ?? null;

        if ($pendingData === null) {
            return [];
        }

        return DB::transaction(function () use ($billable, $pendingData): array {
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

            // Redeem coupon if stored in pending.
            $couponApplied = null;
            if (! empty($pendingData['coupon_code'])) {
                try {
                    $coupon = $this->couponService->validate(
                        $pendingData['coupon_code'],
                        $billable,
                        [
                            'planCode' => $context->newPlan,
                            'interval' => $context->newInterval,
                            'addonCodes' => $newAddons,
                            'orderAmountNet' => $newNet,
                        ],
                    );
                    $discount = $this->couponService->computeRecurringDiscount($coupon, $newNet);
                    $this->couponService->redeem($coupon, $billable, [
                        'planCode' => $context->newPlan,
                        'interval' => $context->newInterval,
                        'orderAmountNet' => $newNet,
                        'discount_amount_net' => $discount,
                    ]);
                    $couponApplied = (string) $coupon->code;
                } catch (\Throwable $e) {
                    Log::warning('Coupon redemption failed during applyPendingPlanChange', [
                        'billable' => $billable instanceof Model ? $billable->getKey() : null,
                        'coupon' => $pendingData['coupon_code'],
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

        if ($hasChanges && $periodStart !== null && $periodEnd !== null) {
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
