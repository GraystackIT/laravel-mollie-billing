<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Exceptions\DowngradeRequiresMandateException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mollie\Api\Http\Data\Money;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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
        private readonly ChargeUsageOverageDirectly $overageService,
        private readonly ScheduleSubscriptionChange $scheduleService,
        private readonly RefundInvoiceService $refundService,
        private readonly WalletUsageService $walletService,
    ) {
    }

    public function update(Billable $billable, array|SubscriptionUpdateRequest $request): array
    {
        $dto = SubscriptionUpdateRequest::from($request);

        if ($dto->applyAt === 'end_of_period') {
            if ($this->isUpgrade($billable, $dto)) {
                // Upgrades cannot be scheduled — apply immediately instead.
                $dto = new SubscriptionUpdateRequest(
                    planCode: $dto->planCode,
                    interval: $dto->interval,
                    seats: $dto->seats,
                    addons: $dto->addons,
                    couponCode: $dto->couponCode,
                    applyAt: 'immediate',
                );
            } else {
                $this->scheduleService->schedule($billable, $dto);

                return [
                    'scheduledFor' => $billable->nextBillingDate()?->toIso8601String(),
                ];
            }
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

            $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
            $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
            $currentSeats = $billable->getBillingSeatCount();
            $currentAddons = $billable->getActiveBillingAddonCodes();

            $newPlan = $dto->planCode ?? $currentPlan;
            $newInterval = $dto->interval ?? $currentInterval;
            $planChanged = $newPlan !== $currentPlan;

            // Auto-derive seats from the new plan when not explicitly provided.
            if ($dto->seats !== null) {
                $newSeats = $dto->seats;
            } else {
                $usedSeats = $billable->getUsedBillingSeats();
                $newIncludedSeats = $this->catalog->includedSeats($newPlan);
                $seatPriceNet = $this->catalog->seatPriceNet($newPlan, $newInterval);

                if ($usedSeats > $newIncludedSeats && $seatPriceNet === null) {
                    throw new SeatDowngradeRequiredException($billable, $usedSeats, $newIncludedSeats);
                }

                $newSeats = max($usedSeats, $newIncludedSeats);
            }

            $newAddons = $dto->addons !== null
                ? $this->normalizeAddonCodes($dto->addons)
                : $currentAddons;

            // Auto-strip addons the new plan does not support.
            if ($planChanged) {
                $newAddons = array_values(array_filter(
                    $newAddons,
                    fn (string $code) => $this->catalog->planAllowsAddon($newPlan, $code),
                ));
            }

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

            // Prorata payments: charge for upgrade, refund for immediate downgrade.
            if ($prorataChargeNet > 0) {
                $this->chargeProrataImmediate($billable, $prorataChargeNet);
            } elseif ($prorataCreditNet > 0 && $dto->applyAt === 'immediate') {
                $this->refundProrataCredit($billable, $prorataCreditNet);
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

            // Adjust wallet balances to reflect the new plan's included usage.
            if ($planChanged || $intervalChanged) {
                $this->adjustWalletsForPlanChange($billable, $currentPlan, $currentInterval, $newPlan, $newInterval);
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

    private function isUpgrade(Billable $billable, SubscriptionUpdateRequest $dto): bool
    {
        $currentPlan = $billable->getBillingSubscriptionPlanCode() ?? '';
        $currentInterval = $billable->getBillingSubscriptionInterval() ?? 'monthly';
        $currentSeats = $billable->getBillingSeatCount();
        $currentAddons = $billable->getActiveBillingAddonCodes();

        $newPlan = $dto->planCode ?? $currentPlan;
        $newInterval = $dto->interval ?? $currentInterval;
        $planChanged = $newPlan !== $currentPlan;

        // Auto-derive seats
        $usedSeats = $billable->getUsedBillingSeats();
        $newSeats = $dto->seats ?? max($usedSeats, $this->catalog->includedSeats($newPlan));

        // Auto-filter incompatible addons
        $newAddons = $dto->addons !== null
            ? $this->normalizeAddonCodes($dto->addons)
            : $currentAddons;

        if ($planChanged) {
            $newAddons = array_values(array_filter(
                $newAddons,
                fn (string $code) => $this->catalog->planAllowsAddon($newPlan, $code),
            ));
        }

        $currentNet = $this->computeAmountNet($currentPlan, $currentInterval, $currentSeats, $currentAddons);
        $newNet = $this->computeAmountNet($newPlan, $newInterval, $newSeats, $newAddons);

        return $newNet > $currentNet;
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
     * Create a one-off Mollie payment for the prorata upgrade charge.
     * The webhook handler processes type=prorata and creates a BillingInvoice.
     */
    protected function chargeProrataImmediate(Billable $billable, int $prorataChargeNet): void
    {
        if (! ($billable instanceof Model) || ! $billable->hasMollieMandate()) {
            return;
        }

        $country = $billable->getBillingCountry() ?? 'DE';
        $vat = $this->vatService->calculate($country, $prorataChargeNet, $billable->vat_number ?? null);

        $currency = (string) config('mollie-billing.currency', 'EUR');
        $amountValue = number_format($vat['gross'] / 100, 2, '.', '');

        $lineItems = [[
            'kind' => 'prorata',
            'label' => 'Pro-rata upgrade charge',
            'quantity' => 1,
            'unit_price_net' => $prorataChargeNet,
            'total_net' => $prorataChargeNet,
        ]];

        $payment = Mollie::send(new CreatePaymentRequest(
            description: 'Pro-rata plan upgrade',
            amount: new Money($currency, $amountValue),
            metadata: [
                'type' => 'prorata',
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
                'line_items' => $lineItems,
            ],
            sequenceType: 'recurring',
            mandateId: $billable->getMollieMandateId(),
            customerId: $billable->getMollieCustomerId(),
        ));

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['prorata_pending_payment_id'] = is_object($payment) ? ($payment->id ?? null) : null;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    /**
     * Issue a partial refund on the last subscription invoice for the prorata
     * credit from an immediate downgrade. Creates both a Mollie refund and a
     * local credit-note invoice.
     */
    protected function refundProrataCredit(Billable $billable, int $prorataCreditNet): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        $latestInvoice = $billable->billingInvoices()
            ->where('invoice_kind', 'subscription')
            ->where('status', 'paid')
            ->whereNotNull('mollie_payment_id')
            ->first();

        if ($latestInvoice === null) {
            return;
        }

        if ($prorataCreditNet > $latestInvoice->remainingRefundableNet()) {
            $prorataCreditNet = $latestInvoice->remainingRefundableNet();
        }

        if ($prorataCreditNet <= 0) {
            return;
        }

        try {
            $this->refundService->refundPartially(
                $latestInvoice,
                $prorataCreditNet,
                RefundReasonCode::PlanDowngrade,
                'Pro-rata credit for plan downgrade',
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Prorata refund failed', [
                'billable' => $billable->getKey(),
                'invoice' => $latestInvoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Adjust wallet balances when the plan or interval changes.
     *
     * Upgrade (more quota): credit the difference.
     * Downgrade (less quota): cap the balance to the new plan's quota.
     * Note: assertDowngradesAllowed() runs BEFORE this and charges overage
     * for used units above the new quota.
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

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $oldIncluded = $this->catalog->includedUsage($oldPlan, $oldInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);
            $balance = (int) $wallet->balanceInt;

            $diff = $newIncluded - $oldIncluded;

            if ($diff > 0) {
                $wallet->deposit($diff, ['type' => $slug, 'reason' => 'plan_change_upgrade']);
            } elseif ($diff < 0) {
                $newBalance = max(0, $balance + $diff);
                $withdraw = $balance - $newBalance;
                if ($withdraw > 0) {
                    $wallet->forceWithdraw($withdraw, ['type' => $slug, 'reason' => 'plan_change_downgrade']);
                }
            }
        }

        // Create wallets for usage types that are new in the target plan.
        $newUsages = $this->catalog->includedUsages($newPlan, $newInterval);
        foreach ($newUsages as $type => $quantity) {
            if ((int) $quantity > 0 && $billable->getWallet($type) === null) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity);
            }
        }
    }
}
