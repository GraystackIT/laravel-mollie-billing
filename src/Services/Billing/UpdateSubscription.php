<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class UpdateSubscription
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly PreviewService $previewService,
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly VatCalculationService $vatService,
        private readonly ChargeUsageOverageDirectly $overageService,
        private readonly ScheduleSubscriptionChange $scheduleService,
    ) {
    }

    public function update(Billable $billable, array|SubscriptionUpdateRequest $request): array
    {
        $dto = SubscriptionUpdateRequest::from($request);

        if ($dto->applyAt === 'end_of_period') {
            $this->scheduleService->schedule($billable, $dto);

            return [
                'scheduledFor' => $billable->nextBillingDate()?->toIso8601String(),
            ];
        }

        /** @var Model&Billable $billable */
        return DB::transaction(function () use ($billable, $dto): array {
            if ($billable instanceof Model) {
                $billable->refresh();
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
            $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
            $currentSeats = $billable->getBillingSeatCount();
            $currentAddons = $billable->getActiveBillingAddonCodes();

            $newPlan = $dto->planCode ?? $currentPlan;
            $newInterval = $dto->interval ?? $currentInterval;
            $newSeats = $dto->seats ?? $currentSeats;
            $newAddons = $dto->addons !== null
                ? array_keys(array_filter($dto->addons, fn ($q) => (int) $q > 0))
                : $currentAddons;

            $planChanged = $newPlan !== $currentPlan;
            $intervalChanged = $newInterval !== $currentInterval;
            $seatsChanged = $newSeats !== $currentSeats;
            $addonsAdded = array_values(array_diff($newAddons, $currentAddons));
            $addonsRemoved = array_values(array_diff($currentAddons, $newAddons));

            // Downgrade-guard: usage types where new included is below used.
            // Both plan AND interval can change the included quota — pass both pairs.
            $this->assertDowngradesAllowed($billable, $currentPlan, $currentInterval, $newPlan, $newInterval);

            // Coupon
            $couponApplied = null;
            $couponDiscountNet = 0;

            $newNet = $this->computeAmountNet($newPlan, $newInterval, $newSeats, $newAddons);
            $currentNet = $this->computeAmountNet($currentPlan, $currentInterval, $currentSeats, $currentAddons);

            if ($dto->couponCode !== null && $dto->couponCode !== '') {
                $coupon = $this->couponService->validate(
                    $dto->couponCode,
                    $billable,
                    [
                        'planCode' => $newPlan,
                        'interval' => $newInterval,
                        'addonCodes' => $newAddons,
                        'orderAmountNet' => $newNet,
                    ],
                );
                $couponDiscountNet = $this->couponService->computeRecurringDiscount($coupon, $newNet);
                $this->couponService->redeem($coupon, $billable, [
                    'planCode' => $newPlan,
                    'interval' => $newInterval,
                    'orderAmountNet' => $newNet,
                    'discount_amount_net' => $couponDiscountNet,
                ]);
                $couponApplied = (string) $coupon->code;
            }

            // Prorata
            $prorataChargeNet = 0;
            $prorataCreditNet = 0;
            $periodStart = $billable->getBillingPeriodStartsAt();
            $periodEnd = $billable->nextBillingDate();

            if (
                ($planChanged || $intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)
                && $periodStart !== null
                && $periodEnd !== null
            ) {
                $factor = BillingPolicy::prorataFactor($periodStart, $periodEnd);
                $diff = $newNet - $currentNet;
                if ($diff > 0) {
                    $prorataChargeNet = (int) round($diff * $factor);
                } elseif ($diff < 0) {
                    $prorataCreditNet = (int) round(-$diff * $factor);
                }
            }

            // Mollie patch placeholder
            $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;
            $mollieSubscriptionPatched = false;

            if ($billable instanceof Model) {
                $meta = $billable->getBillingSubscriptionMeta();

                if ($isMollie && ($planChanged || $intervalChanged || $seatsChanged || $addonsAdded || $addonsRemoved)) {
                    // Mollie subscriptions cannot be mutated in place — the standard pattern is
                    // cancel the old one and create a new one with the updated amount.
                    $mollieSubscriptionPatched = $this->cancelAndRecreateMollieSubscription(
                        $billable,
                        $newPlan,
                        $newInterval,
                        array_values($newAddons),
                        max(0, $newSeats - $this->catalog->includedSeats($newPlan)),
                        $newNet,
                    );

                    // Re-read meta — cancelAndRecreate may have written mollie_subscription_id.
                    $billable->refresh();
                    $meta = $billable->getBillingSubscriptionMeta();

                    $meta['pending_amount_net'] = $newNet;
                    $meta['pending_amount_recorded_at'] = now()->toIso8601String();
                }

                // Seat_count
                $meta['seat_count'] = $newSeats;

                $billable->forceFill([
                    'subscription_plan_code' => $newPlan,
                    'subscription_interval' => $newInterval,
                    'active_addon_codes' => array_values($newAddons),
                    'subscription_meta' => $meta,
                ])->save();
            }

            // Events
            $events = [];

            if ($planChanged || $intervalChanged) {
                event(new PlanChanged($billable, $currentPlan ?: null, $newPlan, $newInterval));
                $events[] = PlanChanged::class;
            }

            if ($seatsChanged) {
                event(new SeatsChanged($billable, $currentSeats, $newSeats));
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
                'planChanged' => $planChanged,
                'intervalChanged' => $intervalChanged,
                'seatsChanged' => $seatsChanged,
                'addonsAdded' => $addonsAdded,
                'addonsRemoved' => $addonsRemoved,
            ];

            event(new SubscriptionUpdated($billable, $dto->toArray(), $diff));
            $events[] = SubscriptionUpdated::class;

            return [
                'planChanged' => $planChanged,
                'intervalChanged' => $intervalChanged,
                'seatsChanged' => $seatsChanged,
                'addonsAdded' => $addonsAdded,
                'addonsRemoved' => $addonsRemoved,
                'couponApplied' => $couponApplied,
                'prorataChargeNet' => $prorataChargeNet,
                'prorataCreditNet' => $prorataCreditNet,
                'mollieSubscriptionPatched' => $mollieSubscriptionPatched,
                'appliedAt' => now()->toIso8601String(),
                'scheduledFor' => null,
                'events' => $events,
            ];
        });
    }

    public function cancelScheduledChange(Billable $billable): void
    {
        $this->scheduleService->cancel($billable);
    }

    /**
     * For each wallet, if used > new plan's included quota, either charge the overage
     * (requires mandate) or throw.
     */
    private function assertDowngradesAllowed(
        Billable $billable,
        string $currentPlan,
        string $currentInterval,
        string $newPlan,
        string $newInterval,
    ): void {
        if (! $billable instanceof Model) {
            return;
        }

        $lineItems = [];

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $used = $billable->usedBillingQuota($slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);

            if ($used <= $newIncluded) {
                continue;
            }

            $overageQty = $used - $newIncluded;
            $overagePrice = (int) ($this->catalog->usageOveragePrice($currentPlan, $currentInterval, $slug) ?? 0);

            if (! $billable->hasMollieMandate()) {
                throw new DowngradeRequiresMandateException($billable, $newPlan);
            }

            if ($overagePrice > 0) {
                $lineItems[] = [
                    'type' => $slug,
                    'quantity' => $overageQty,
                    'unit_price_net' => $overagePrice,
                    'total_net' => $overageQty * $overagePrice,
                ];
            }
        }

        if ($lineItems !== []) {
            $this->overageService->handleExplicit($billable, $lineItems);
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
                'webhookUrl' => url(route('billing.webhook', [], false)),
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
            \Illuminate\Support\Facades\Log::warning('Mollie cancel+create failed', [
                'billable' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
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
}
