<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Rebalances wallets when a subscription's plan or interval changes.
 *
 * Plan-only credits are prorated against actual usage; purchased credits
 * (one-time orders, coupon credits) are preserved across the change.
 * Unresolvable overage (negative balance after rebalancing) is charged
 * via Mollie when a mandate is available.
 */
class WalletPlanChangeAdjuster
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly WalletUsageService $walletService,
        private readonly ChargeUsageOverageDirectly $overageService,
    ) {
    }

    /**
     * Rebalance the wallets for a plan/interval change.
     *
     * The proration baseline is the **old** billing period. Callers save the new
     * plan, the new interval and sometimes a new period start *before* calling
     * this, so it cannot be read back off the model — pass `$oldPeriodStart` and
     * `$oldPeriodEnd` explicitly.
     *
     * Fallbacks when they are omitted:
     *   - `$oldPeriodStart` falls back to the model's `subscription_period_starts_at`;
     *   - `$oldPeriodEnd` is derived from that start plus **`$oldInterval`**, never
     *     from `nextBillingDate()` (which would already use the new interval);
     *   - if the derived period turns out not to have elapsed at all, the excess
     *     computation is skipped entirely instead of treating a whole period's
     *     legitimate in-quota usage as excess. A reset `subscription_period_starts_at`
     *     is the usual cause, and the real baseline is then unrecoverable — billing
     *     a charge out of missing data is never the safe direction.
     *
     * @param  CarbonInterface|null  $oldPeriodStart  start of the period being left
     * @param  CarbonInterface|null  $oldPeriodEnd  end of the period being left
     */
    public function adjust(
        Billable $billable,
        string $oldPlan,
        string $oldInterval,
        string $newPlan,
        string $newInterval,
        ?CarbonInterface $oldPeriodStart = null,
        ?CarbonInterface $oldPeriodEnd = null,
    ): void {
        if (! ($billable instanceof Model)) {
            return;
        }

        $boundariesExplicit = $oldPeriodStart !== null;
        $periodStart = $oldPeriodStart ?? $billable->getBillingPeriodStartsAt();
        $periodEnd = $oldPeriodEnd ?? $this->derivePeriodEnd($periodStart, $oldInterval);

        $prorationUsable = $this->prorationIsUsable(
            $billable,
            $boundariesExplicit,
            $periodStart,
            $periodEnd,
        );

        $overageLineItems = [];

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $rollover = $this->catalog->usageRollover($slug);
            $oldIncluded = $this->catalog->includedUsage($oldPlan, $oldInterval, $slug);
            $newIncluded = $this->catalog->includedUsage($newPlan, $newInterval, $slug);
            $balance = (int) $wallet->balanceInt;

            // Separate purchased credits from plan credits. Purchased credits
            // are consumed last — plan quota first. The remaining purchased
            // balance is preserved across the plan change.
            $purchasedBalance = WalletUsageService::getPurchasedBalance($wallet);
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
            $planOnlyBalance = $balance - $purchasedRemaining;

            $excess = 0;
            if ($prorationUsable && $oldIncluded > 0) {
                /** @var CarbonInterface $periodStart */
                /** @var CarbonInterface $periodEnd */
                $result = BillingPolicy::computeUsageOverageForPlanChange(
                    $oldIncluded,
                    $planOnlyBalance,
                    $periodStart,
                    $periodEnd,
                );
                $excess = $result['excess'];
            }

            $rolloverCredits = $rollover ? max(0, $planOnlyBalance - $oldIncluded) : 0;
            $targetBalance = $newIncluded + $rolloverCredits + $purchasedRemaining - $excess;

            if ($targetBalance < 0) {
                $unresolvedOverage = abs($targetBalance);
                $targetBalance = 0;

                $overagePrice = (int) ($this->catalog->usageOveragePrice($oldPlan, $oldInterval, $slug) ?? 0);
                if ($overagePrice > 0) {
                    $overageLineItems[] = [
                        'kind' => 'overage',
                        'code' => $slug,
                        'type' => $slug,
                        'label' => (string) __('billing::portal.invoice_line_overage', [
                            'type' => $this->catalog->usageTypeName($slug),
                        ]),
                        'quantity' => $unresolvedOverage,
                        'unit_price_net' => $overagePrice,
                        'total_net' => $unresolvedOverage * $overagePrice,
                    ];
                }
            }

            if ($balance > 0) {
                $wallet->forceWithdraw($balance, ['type' => $slug, 'reason' => 'plan_change_reset']);
            } elseif ($balance < 0) {
                $wallet->deposit(abs($balance), ['type' => $slug, 'reason' => 'plan_change_reset']);
            }

            if ($targetBalance > 0) {
                $wallet->deposit($targetBalance, ['type' => $slug, 'reason' => 'plan_change_credit']);
            }

            // purchased_balance must not exceed the actual wallet balance.
            WalletUsageService::setPurchasedBalance($wallet, min($purchasedRemaining, max(0, $targetBalance)));
        }

        // Create wallets for usage types that are new in the target plan.
        $newUsages = $this->catalog->includedUsages($newPlan, $newInterval);
        foreach ($newUsages as $type => $quantity) {
            if ((int) $quantity > 0 && $billable->getWallet($type) === null) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'plan_change_credit');
            }
        }

        if ($overageLineItems !== [] && $billable->hasMollieMandate()) {
            // The wallets are already zeroed at this point, so the charge can no
            // longer be reconstructed from them. handleExplicit() persists the
            // line items and marks the charge pending, which is what makes a
            // declined payment recoverable via ChargeUsageOverageDirectly::retry().
            $result = $this->overageService->handleExplicit($billable, $overageLineItems);

            if (! $result->wasCharged()) {
                Log::warning('Plan-change overage was not charged', [
                    'billable_id' => $billable->getKey(),
                    'outcome' => $result->outcome->value,
                    'line_items' => $overageLineItems,
                ]);
            }
        }
    }

    /**
     * End of the period being left: start + the *old* interval.
     */
    private function derivePeriodEnd(?CarbonInterface $periodStart, string $oldInterval): ?CarbonInterface
    {
        if ($periodStart === null) {
            return null;
        }

        return strtolower($oldInterval) === SubscriptionInterval::Yearly->value
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();
    }

    /**
     * Whether the resolved boundaries can carry a prorated entitlement.
     *
     * Derived boundaries with a zero elapsed fraction mean the period start was
     * overwritten before this ran: the prorated entitlement would be 0 and every
     * unit consumed in the old period would be billed as excess.
     */
    private function prorationIsUsable(
        Model $billable,
        bool $boundariesExplicit,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
    ): bool {
        if ($periodStart === null || $periodEnd === null) {
            return false;
        }

        if ($boundariesExplicit) {
            return true;
        }

        $elapsedFraction = round(1.0 - BillingPolicy::prorataFactor($periodStart, $periodEnd), 6);

        if ($elapsedFraction > 0.0) {
            return true;
        }

        Log::warning(
            'WalletPlanChangeAdjuster: no elapsed time in the derived old period — skipping usage proration. '
            .'Pass $oldPeriodStart/$oldPeriodEnd from the caller to bill plan-change excess.',
            [
                'billable_id' => $billable->getKey(),
                'period_start' => $periodStart->toIso8601String(),
                'period_end' => $periodEnd->toIso8601String(),
            ]
        );

        return false;
    }
}
