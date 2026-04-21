<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\PlanChangePending;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class UpdateSubscription
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly PreviewService $previewService,
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly ValidateSubscriptionChange $validator,
        private readonly ScheduleSubscriptionChange $scheduleService,
        private readonly WalletUsageService $walletService,
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

            // ── Deferred upgrade: Mollie subscription with prorata charge ──
            // Store pending change, create payment, return without modifying the plan.
            if ($context->prorataChargeNet > 0 && $context->isMollie) {
                $meta = $billable->getBillingSubscriptionMeta();
                $meta['pending_plan_change'] = $context->toPendingArray();
                $billable->forceFill(['subscription_meta' => $meta])->save();

                $this->chargeProrataImmediate($billable, $context->prorataChargeNet, $context);

                $billable->refresh();
                $paymentId = (string) ($billable->getBillingSubscriptionMeta()['prorata_pending_payment_id'] ?? '');

                event(new PlanChangePending($billable, $meta['pending_plan_change'], $paymentId));

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
                    'events' => [PlanChangePending::class],
                ];
            }

            // ── Immediate apply (downgrades, zero-cost, local subscriptions) ──

            // Redeem coupon now (only for immediate applies).
            if ($dto->couponCode !== null && $dto->couponCode !== '' && isset($coupon)) {
                $this->couponService->redeem($coupon, $billable, [
                    'planCode' => $context->newPlan,
                    'interval' => $context->newInterval,
                    'orderAmountNet' => $newNet,
                    'discount_amount_net' => $couponDiscountNet,
                ]);
                $couponApplied = (string) $coupon->code;
            }

            // Prorata: refund for immediate downgrade.
            if ($context->prorataChargeNet > 0) {
                $this->chargeProrataImmediate($billable, $context->prorataChargeNet, $context);
            } elseif ($context->prorataCreditNet > 0 && $dto->applyAt === 'immediate') {
                $this->refundProrataCredit($billable, $context->prorataCreditNet, $context);
            }

            $mollieSubscriptionPatched = false;

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();

                if ($context->isMollie && ($context->planChanged || $context->intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)) {
                    $mollieSubscriptionPatched = $this->cancelAndRecreateMollieSubscription(
                        $billable,
                        $context->newPlan,
                        $context->newInterval,
                        array_values($newAddons),
                        max(0, $newSeats - $this->catalog->includedSeats($context->newPlan)),
                        $newNet,
                    );

                    if (! $mollieSubscriptionPatched) {
                        Log::warning('Mollie subscription was not patched — cancelAndRecreate returned false', [
                            'billable' => $billable->getKey(),
                        ]);
                    }

                    $billable->refresh();
                    $meta = $billable->getBillingSubscriptionMeta();

                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = now()->toIso8601String();
                }

                $meta['seat_count'] = $newSeats;

                $billable->forceFill([
                    'subscription_plan_code' => $context->newPlan,
                    'subscription_interval' => $context->newInterval,
                    'active_addon_codes' => array_values($newAddons),
                    'subscription_meta' => $meta,
                ])->save();
            }

            if ($context->planChanged || $context->intervalChanged) {
                $this->adjustWalletsForPlanChange($billable, $context->currentPlan, $context->currentInterval, $context->newPlan, $context->newInterval);
            }

            return $this->dispatchEventsAndBuildResult(
                $billable, $dto, $context, $newSeats, $newAddons, $addonsAdded, $addonsRemoved,
                $seatsChanged, $couponApplied, $mollieSubscriptionPatched,
            );
        });
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
                $mollieSubscriptionPatched = $this->cancelAndRecreateMollieSubscription(
                    $billable,
                    $context->newPlan,
                    $context->newInterval,
                    array_values($newAddons),
                    max(0, $newSeats - $this->catalog->includedSeats($context->newPlan)),
                    $newNet,
                );
            }

            // Clear pending state.
            $this->clearPendingPlanChange($billable);
            $billable->refresh();

            $meta = $billable->getBillingSubscriptionMeta();
            $meta['seat_count'] = $newSeats;
            $meta['pending_amount_net'] = $newNet;
            $meta['pending_amount_recorded_at'] = now()->toIso8601String();

            $billable->forceFill([
                'subscription_plan_code' => $context->newPlan,
                'subscription_interval' => $context->newInterval,
                'active_addon_codes' => array_values($newAddons),
                'subscription_meta' => $meta,
            ])->save();

            if ($context->planChanged || $context->intervalChanged) {
                $this->adjustWalletsForPlanChange(
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
        unset($meta['pending_plan_change'], $meta['prorata_pending_payment_id']);
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

        $newNet = $this->computeAmountNet($newPlan, $newInterval, $newSeats, $newAddons);
        $currentNet = $this->computeAmountNet($currentPlan, $currentInterval, $currentSeats, $currentAddons);

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
            'appliedAt' => now()->toIso8601String(),
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
        $mode = config('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice);

        if ($dto->applyAt === 'end_of_period' && $mode === PlanChangeMode::Immediate) {
            throw new \RuntimeException('Scheduled plan changes are not allowed.');
        }

        if ($dto->applyAt !== 'end_of_period' && $mode === PlanChangeMode::EndOfPeriod) {
            throw new \RuntimeException('Immediate plan changes are not allowed.');
        }
    }

    /**
     * @param  array<int, string>  $addons
     */
    private function computeAmountNet(
        string $planCode,
        string $interval,
        int $seats,
        array $addons,
    ): int {
        if ($planCode === '') {
            return 0;
        }

        $base = $this->catalog->basePriceNet($planCode, $interval);
        $includedSeats = $this->catalog->includedSeats($planCode);
        $seatPrice = $this->catalog->seatPriceNet($planCode, $interval);
        $extraSeats = max(0, $seats - $includedSeats);

        $total = $base;
        if ($seatPrice !== null && $extraSeats > 0) {
            $total += $seatPrice * $extraSeats;
        }

        foreach ($addons as $addonCode) {
            $total += $this->catalog->addonPriceNet((string) $addonCode, $interval);
        }

        return $total;
    }

    /**
     * Cancel the current Mollie subscription and immediately create a new one with the
     * updated amount. Returns true on success.
     *
     * @param  array<int, string>  $addons
     */
    protected function cancelAndRecreateMollieSubscription(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addons,
        int $extraSeats,
        int $amountNet,
    ): bool {
        if (! ($billable instanceof Model)) {
            return false;
        }

        $customerId = $billable->getMollieCustomerId();
        $currentSubId = (string) ($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? '');

        if ($customerId === null || $currentSubId === '') {
            Log::warning('cancelAndRecreateMollieSubscription skipped — missing Mollie identifiers', [
                'billable' => $billable->getKey(),
                'mollie_customer_id' => $customerId,
                'mollie_subscription_id' => $currentSubId ?: '(empty)',
            ]);

            return false;
        }

        try {
            $vat = $this->vatService->calculate(
                (string) ($billable->getBillingCountry() ?? ''),
                $amountNet,
                $billable->vat_number,
            );

            $this->mollieCancelSubscription($customerId, $currentSubId);

            $newSubscription = $this->mollieCreateSubscription($customerId, [
                'amount' => [
                    'currency' => (string) config('mollie-billing.currency', 'EUR'),
                    'value' => number_format(((int) $vat['gross']) / 100, 2, '.', ''),
                ],
                'interval' => $interval === 'yearly' ? '12 months' : '1 month',
                'description' => "{$planCode} subscription",
                'webhookUrl' => route(BillingRoute::webhook()),
                'metadata' => [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => (string) $billable->getKey(),
                    'plan_code' => $planCode,
                    'interval' => $interval,
                    'addon_codes' => $addons,
                    'extra_seats' => $extraSeats,
                ],
            ]);

            $meta = $billable->getBillingSubscriptionMeta();
            $meta['mollie_subscription_id'] = (string) ($newSubscription->id ?? '');
            $billable->forceFill(['subscription_meta' => $meta])->save();

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Mollie cancel+create failed', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function mollieCancelSubscription(string $customerId, string $subscriptionId): void
    {
        Mollie::send(new CancelSubscriptionRequest($customerId, $subscriptionId));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mollieCreateSubscription(string $customerId, array $payload): object
    {
        return Mollie::send(new CreateSubscriptionRequest(
            customerId: $customerId,
            amount: new Money(
                (string) $payload['amount']['currency'],
                (string) $payload['amount']['value'],
            ),
            interval: (string) $payload['interval'],
            description: (string) $payload['description'],
            metadata: $payload['metadata'] ?? null,
            webhookUrl: $payload['webhookUrl'] ?? null,
        ));
    }

    /**
     * Create a one-off Mollie payment for a prorata upgrade charge.
     *
     * The invoice kind and line items are derived from the change context:
     * - Plan/interval change → kind 'prorata', label 'Pro-rata plan upgrade'
     * - Addon added → kind 'addon', label per addon
     * - Seats increased → kind 'prorata', label 'Extra seats (pro-rata)'
     */
    protected function chargeProrataImmediate(Billable $billable, int $prorataChargeNet, ?SubscriptionChangeContext $context = null): void
    {
        if (! ($billable instanceof Model) || ! $billable->hasMollieMandate()) {
            Log::warning('chargeProrataImmediate skipped — no Mollie mandate', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'has_mandate' => $billable instanceof Model ? $billable->hasMollieMandate() : false,
            ]);

            return;
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vat = $this->vatService->calculate($country, $prorataChargeNet, $billable->vat_number ?? null);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountValue = number_format($vat['gross'] / 100, 2, '.', '');

        $chargeInfo = $this->resolveChargeInfo($prorataChargeNet, $context, $billable);

        $payment = Mollie::send(new CreatePaymentRequest(
            description: $chargeInfo['description'],
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => $chargeInfo['type'],
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'line_items' => $chargeInfo['line_items'],
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
            webhookUrl: route(BillingRoute::webhook()),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['prorata_pending_payment_id'] = is_object($payment) ? ($payment->id ?? null) : null;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Derive the payment type, description, and line items from the change context.
     *
     * @return array{type: string, description: string, line_items: array}
     */
    private function resolveChargeInfo(int $prorataChargeNet, ?SubscriptionChangeContext $context, ?Billable $billable = null): array
    {
        if ($context === null) {
            return [
                'type' => 'prorata',
                'description' => 'Pro-rata upgrade charge',
                'line_items' => [[
                    'kind' => 'prorata',
                    'label' => 'Pro-rata upgrade charge',
                    'quantity' => 1,
                    'unit_price_net' => $prorataChargeNet,
                    'total_net' => $prorataChargeNet,
                ]],
            ];
        }

        $addonsAdded = array_values(array_diff($context->newAddons, $context->currentAddons));
        $seatsChanged = $context->newSeats !== $context->currentSeats;

        $onlyAddonsChanged = ! $context->planChanged
            && ! $context->intervalChanged
            && ! $seatsChanged
            && ! empty($addonsAdded);

        $onlySeatsChanged = ! $context->planChanged
            && ! $context->intervalChanged
            && $seatsChanged
            && empty($addonsAdded);

        if ($onlyAddonsChanged) {
            $addonCount = count($addonsAdded);
            $perAddonNet = $addonCount > 0 ? intdiv($prorataChargeNet, $addonCount) : $prorataChargeNet;
            $lineItems = [];
            foreach ($addonsAdded as $i => $code) {
                $addonName = $this->catalog->addonName($code) ?? $code;
                $isLast = $i === $addonCount - 1;
                $lineTotal = $isLast
                    ? $prorataChargeNet - $perAddonNet * ($addonCount - 1)
                    : $perAddonNet;
                $lineItems[] = [
                    'kind' => 'addon',
                    'label' => $addonName.' ('.__('billing::portal.prorata').')',
                    'code' => $code,
                    'quantity' => 1,
                    'unit_price_net' => $lineTotal,
                    'total_net' => $lineTotal,
                ];
            }

            return [
                'type' => 'addon',
                'description' => 'Addon: '.implode(', ', array_map(
                    fn (string $c) => $this->catalog->addonName($c) ?? $c,
                    $addonsAdded,
                )),
                'line_items' => $lineItems,
            ];
        }

        if ($onlySeatsChanged) {
            $extraSeats = $context->newSeats - $context->currentSeats;
            $unitPriceNet = $extraSeats > 0 ? intdiv($prorataChargeNet, $extraSeats) : $prorataChargeNet;

            return [
                'type' => 'seats',
                'description' => 'Extra seats ('.$extraSeats.')',
                'line_items' => [[
                    'kind' => 'seat',
                    'label' => 'Extra seats ('.__('billing::portal.prorata').')',
                    'quantity' => $extraSeats,
                    'unit_price_net' => $unitPriceNet,
                    'total_net' => $prorataChargeNet,
                ]],
            ];
        }

        // Plan/interval change: build detailed line items mirroring the preview.
        //
        // The preview shows two lines:
        //   "Neuer Plan (verbleibende Periode)"  →  newPlanProrata
        //   "Gutschrift aktueller Plan"          → -creditProrata
        // Their difference equals $prorataChargeNet.
        //
        // For same-interval changes:
        //   newPlanProrata  = newNet * factor
        //   creditProrata   = currentNet * factor
        //   charge          = (newNet - currentNet) * factor  ← $prorataChargeNet
        //
        // For interval changes:
        //   newPlanProrata  = newNet  (full price, new subscription starts fresh)
        //   creditProrata   = currentNet * factor  (unused portion of old period)
        //   charge          = newNet - creditProrata  ← $prorataChargeNet (only when positive)
        $currentPlanName = $this->catalog->planName($context->currentPlan) ?? $context->currentPlan;
        $newPlanName = $this->catalog->planName($context->newPlan) ?? $context->newPlan;

        $currentLabel = $currentPlanName.' ('.__('billing::enums.subscription_interval.'.$context->currentInterval).')';
        $newLabel = $newPlanName.' ('.__('billing::enums.subscription_interval.'.$context->newInterval).')';

        $description = $currentPlanName.' -> '.$newPlanName;
        if ($context->intervalChanged) {
            $description .= ' ('.__('billing::enums.subscription_interval.'.$context->newInterval).')';
        }

        // Compute billing periods for line-item descriptions.
        $currentPeriod = null;
        $newPeriod = null;
        if ($billable instanceof Model) {
            $periodStart = $billable->getBillingPeriodStartsAt();
            $periodEnd = $billable->nextBillingDate();
            if ($periodStart !== null && $periodEnd !== null) {
                $currentPeriod = $periodStart->format('d.m.Y').' - '.$periodEnd->format('d.m.Y');
            }
            if ($context->intervalChanged) {
                $newStart = now();
                $newEnd = $context->newInterval === 'yearly' ? now()->addYear() : now()->addMonth();
                $newPeriod = $newStart->format('d.m.Y').' - '.$newEnd->format('d.m.Y');
            } else {
                $newPeriod = $currentPeriod;
            }
        }

        $lineItems = [];

        if ($context->intervalChanged) {
            // Interval change: new plan at full price, credit for unused old period.
            $newPlanProrata = $context->newNet;
            $creditProrata = $newPlanProrata - $prorataChargeNet;
        } else {
            // Same interval: both amounts are prorated by the same factor.
            // factor = prorataChargeNet / (newNet - currentNet) when diff ≠ 0.
            $diff = $context->newNet - $context->currentNet;
            if ($diff !== 0) {
                $factor = $prorataChargeNet / $diff;
            } else {
                $factor = 0.0;
            }
            $newPlanProrata = (int) round($context->newNet * $factor);
            $creditProrata = (int) round($context->currentNet * $factor);
        }

        // Line 1: New plan charge (remaining period).
        if ($newPlanProrata > 0) {
            $lineItems[] = [
                'kind' => 'plan',
                'label' => __('billing::portal.preview_prorata_new_plan').': '.$newLabel,
                'code' => $context->newPlan,
                'quantity' => 1,
                'unit_price_net' => $newPlanProrata,
                'total_net' => $newPlanProrata,
                'billing_period' => $newPeriod,
            ];
        }

        // Line 2: Credit for unused portion of current plan.
        if ($creditProrata > 0) {
            $lineItems[] = [
                'kind' => 'plan_credit',
                'label' => __('billing::portal.preview_prorata_credit').': '.$currentLabel,
                'code' => $context->currentPlan,
                'quantity' => 1,
                'unit_price_net' => -$creditProrata,
                'total_net' => -$creditProrata,
                'billing_period' => $currentPeriod,
            ];
        }

        // If we couldn't build detailed items (edge case), fall back to single line.
        if (empty($lineItems)) {
            $lineItems[] = [
                'kind' => 'prorata',
                'label' => __('billing::portal.preview_prorata_new_plan').': '.$description,
                'quantity' => 1,
                'unit_price_net' => $prorataChargeNet,
                'total_net' => $prorataChargeNet,
            ];
        }

        // Ensure line items sum matches the charge amount (guard against rounding).
        $lineItemSum = array_sum(array_column($lineItems, 'total_net'));
        if ($lineItemSum !== $prorataChargeNet) {
            $diff = $prorataChargeNet - $lineItemSum;
            $lineItems[] = [
                'kind' => 'adjustment',
                'label' => __('billing::portal.prorata').' — '.$description,
                'quantity' => 1,
                'unit_price_net' => $diff,
                'total_net' => $diff,
            ];
        }

        return [
            'type' => 'prorata',
            'description' => $description,
            'line_items' => $lineItems,
        ];
    }

    /**
     * Refund the prorata credit for an immediate downgrade.
     *
     * Creates a Mollie refund against the most recent paid payment (preferring
     * one linked to the current mandate) and a standalone credit-note invoice
     * that is not tied to any parent invoice.
     */
    protected function refundProrataCredit(Billable $billable, int $prorataCreditNet, ?SubscriptionChangeContext $context = null): void
    {
        if (! ($billable instanceof Model) || ! $billable->hasMollieMandate()) {
            return;
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vatResult = $this->vatService->calculate($country, $prorataCreditNet, $billable->vat_number ?? null);
        $vatRate = (float) $vatResult['rate'];
        $refundGross = (int) $vatResult['gross'];

        $reasonText = 'Pro-rata credit for plan downgrade';
        $lineItems = [];

        if ($context !== null) {
            $currentPlanName = $this->catalog->planName($context->currentPlan) ?? $context->currentPlan;
            $newPlanName = $this->catalog->planName($context->newPlan) ?? $context->newPlan;
            $currentLabel = $currentPlanName.' ('.__('billing::enums.subscription_interval.'.$context->currentInterval).')';
            $newLabel = $newPlanName.' ('.__('billing::enums.subscription_interval.'.$context->newInterval).')';

            $reasonText = $currentPlanName.' -> '.$newPlanName;

            if ($context->intervalChanged) {
                $unusedCredit = $prorataCreditNet + $context->newNet;
                $lineItems = [
                    [
                        'kind' => 'plan',
                        'label' => __('billing::portal.preview_prorata_new_plan').': '.$newLabel,
                        'code' => $context->newPlan,
                        'quantity' => 1,
                        'unit_price_net' => $context->newNet,
                        'total_net' => $context->newNet,
                    ],
                    [
                        'kind' => 'plan_credit',
                        'label' => __('billing::portal.preview_prorata_credit').': '.$currentLabel,
                        'code' => $context->currentPlan,
                        'quantity' => 1,
                        'unit_price_net' => -$unusedCredit,
                        'total_net' => -$unusedCredit,
                    ],
                ];
            } else {
                $lineItems = [[
                    'kind' => 'plan_credit',
                    'label' => __('billing::portal.preview_prorata_credit').': '.$currentLabel,
                    'code' => $context->currentPlan,
                    'description' => __('billing::portal.preview_prorata_new_plan').': '.$newLabel,
                    'quantity' => 1,
                    'unit_price_net' => -$prorataCreditNet,
                    'total_net' => -$prorataCreditNet,
                ]];
            }
        }

        // Find a paid Mollie payment to issue the refund against.
        // Prefer the most recent payment linked to the current mandate.
        // The credit-note invoice itself is standalone (no parent_invoice_id).
        $mandateId = $billable->getMollieMandateId();
        $customerId = $billable->getMollieCustomerId();

        $refundPaymentId = $this->findRefundablePaymentId($billable, $customerId, $mandateId);

        if ($refundPaymentId === null) {
            Log::warning('Prorata credit skipped — no paid Mollie payment found to refund against', [
                'billable' => $billable->getKey(),
                'prorata_credit_net' => $prorataCreditNet,
            ]);

            return;
        }

        $currency = (string) config('mollie-billing.currency', 'EUR');

        try {
            Mollie::send(new \Mollie\Api\Http\Requests\CreatePaymentRefundRequest(
                paymentId: $refundPaymentId,
                description: 'Pro-rata credit: '.$reasonText,
                amount: new Money($currency, number_format($refundGross / 100, 2, '.', '')),
            ));
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            if ($e->getCode() === 409) {
                // Duplicate refund — Mollie already processed it (e.g. retry after
                // a previous partial failure). Only create credit note if one doesn't already exist.
                Log::info('Mollie duplicate refund detected (409), checking for existing credit note', [
                    'billable' => $billable->getKey(),
                    'payment_id' => $refundPaymentId,
                    'amount_gross' => $refundGross,
                ]);

                $existingCreditNote = BillingInvoice::query()
                    ->where('billable_type', $billable->getMorphClass())
                    ->where('billable_id', $billable->getKey())
                    ->where('invoice_kind', InvoiceKind::CreditNote)
                    ->where('mollie_payment_id', 'like', $refundPaymentId.':cn:%')
                    ->where('amount_net', -$prorataCreditNet)
                    ->exists();

                if ($existingCreditNote) {
                    Log::info('Credit note already exists for this refund, skipping', [
                        'billable' => $billable->getKey(),
                        'payment_id' => $refundPaymentId,
                    ]);

                    return;
                }
            } else {
                throw $e;
            }
        }

        // Create a standalone credit-note invoice (no parent invoice).
        $invoiceService = app(InvoiceService::class);
        $creditNote = $invoiceService->createStandaloneCreditNote(
            $billable,
            $prorataCreditNet,
            $vatRate,
            $lineItems,
            $reasonText,
            $refundPaymentId,
        );
        $creditNote->refund_reason_code = RefundReasonCode::PlanDowngrade;
        $creditNote->save();
    }

    /**
     * Find the best Mollie payment ID to refund against.
     *
     * Strategy: use the Mollie API to list the customer's payments and pick
     * the most recent paid one that belongs to the current mandate. Falls
     * back to any paid payment if no mandate-specific one is found.
     */
    private function findRefundablePaymentId(Billable $billable, ?string $customerId, ?string $mandateId): ?string
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        try {
            $payments = Mollie::send(new \Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest(
                customerId: $customerId,
                limit: 50,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to list Mollie payments for refund target', [
                'billable' => $billable instanceof Model ? $billable->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $fallback = null;

        foreach ($payments as $payment) {
            if (($payment->status ?? '') !== 'paid') {
                continue;
            }

            // Prefer a payment linked to the current mandate.
            if ($mandateId !== null && ($payment->mandateId ?? null) === $mandateId) {
                return (string) $payment->id;
            }

            // Track first paid payment as fallback.
            if ($fallback === null) {
                $fallback = (string) $payment->id;
            }
        }

        return $fallback;
    }

    /**
     * Adjust wallet balances when the plan or interval changes.
     *
     * For each wallet:
     * 1. Compute prorated excess from the old plan (anteilig überschüssiger Verbrauch).
     * 2. Compute rollover credits (only when usage_rollover is enabled).
     * 3. Reset wallet to the new plan's full quota + rollover credits - excess.
     * 4. If target balance < 0, the remainder is charged as overage.
     */
    private function adjustWalletsForPlanChange(
        Billable $billable,
        string $oldPlan,
        string $oldInterval,
        string $newPlan,
        string $newInterval,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $periodStart = $billable->getBillingPeriodStartsAt();
        $periodEnd = $billable->nextBillingDate();
        $rollover = $this->catalog->usageRollover($oldPlan);
        $overageLineItems = [];

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $oldIncluded = $this->catalog->includedUsage($oldPlan, $oldInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);
            $balance = (int) $wallet->balanceInt;

            // Step 1: Compute prorated excess from old plan.
            $excess = 0;
            if ($periodStart !== null && $periodEnd !== null && $oldIncluded > 0) {
                $result = BillingPolicy::computeUsageOverageForPlanChange(
                    $oldIncluded,
                    $balance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $result['excess'];
            }

            // Step 2: Compute rollover credits.
            $rolloverCredits = $rollover ? max(0, $balance - $oldIncluded) : 0;

            // Step 3: Compute target balance.
            $targetBalance = $newIncluded + $rolloverCredits - $excess;

            // Step 4: If target < 0, charge the unresolvable remainder as overage.
            if ($targetBalance < 0) {
                $unresolvedOverage = abs($targetBalance);
                $targetBalance = 0;

                $overagePrice = (int) ($this->catalog->usageOveragePrice($oldPlan, $oldInterval, $slug) ?? 0);
                if ($overagePrice > 0) {
                    $overageLineItems[] = [
                        'type' => $slug,
                        'quantity' => $unresolvedOverage,
                        'unit_price_net' => $overagePrice,
                        'total_net' => $unresolvedOverage * $overagePrice,
                    ];
                }
            }

            // Apply: reset wallet to target balance.
            if ($balance > 0) {
                $wallet->forceWithdraw($balance, ['type' => $slug, 'reason' => 'plan_change_reset']);
            } elseif ($balance < 0) {
                $wallet->deposit(abs($balance), ['type' => $slug, 'reason' => 'plan_change_reset']);
            }

            if ($targetBalance > 0) {
                $wallet->deposit($targetBalance, ['type' => $slug, 'reason' => 'plan_change_credit']);
            }
        }

        // Create wallets for usage types that are new in the target plan.
        $newUsages = $this->catalog->includedUsages($newPlan, $newInterval);
        foreach ($newUsages as $type => $quantity) {
            if ((int) $quantity > 0 && $billable->getWallet($type) === null) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'plan_change_credit');
            }
        }

        // Charge unresolvable overage if any.
        if ($overageLineItems !== [] && $billable->hasMollieMandate()) {
            app(ChargeUsageOverageDirectly::class)->handleExplicit($billable, $overageLineItems);
        }
    }
}
