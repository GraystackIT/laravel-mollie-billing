<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use Illuminate\Database\Eloquent\Model;

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

    public function adjust(
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

            // Separate purchased credits from plan credits. Purchased credits
            // are consumed last — plan quota first. The remaining purchased
            // balance is preserved across the plan change.
            $purchasedBalance = WalletUsageService::getPurchasedBalance($wallet);
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchasedBalance, $balance);
            $planOnlyBalance = $balance - $purchasedRemaining;

            $excess = 0;
            if ($periodStart !== null && $periodEnd !== null && $oldIncluded > 0) {
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
                        'type' => $slug,
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
            $this->overageService->handleExplicit($billable, $overageLineItems);
        }
    }
}
