<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

class BillingPolicy
{
    /**
     * Whole-day breakdown of a billing period: total length and days left from
     * now to periodEnd. Used as the basis for prorata factor and for surfacing
     * "X of Y days remaining" in preview UIs.
     *
     * Granularity is per day (`startOfDay`): a change on the same day as the
     * period start counts the full period as remaining; the current day always
     * counts as a full remaining day.
     *
     * @return array{total:int, remaining:int}
     */
    public static function prorataPeriodDays(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->startOfDay();
        $totalDays = (int) $start->diffInDays($end);

        if ($totalDays <= 0) {
            return ['total' => 0, 'remaining' => 0];
        }

        $remainingDays = (int) now($end->getTimezone())->startOfDay()->diffInDays($end, false);

        return [
            'total' => $totalDays,
            'remaining' => max(0, $remainingDays),
        ];
    }

    /**
     * Remaining fraction of the period from now to periodEnd, in [0, 1].
     */
    public static function prorataFactor(CarbonInterface $periodStart, CarbonInterface $periodEnd): float
    {
        $days = self::prorataPeriodDays($periodStart, $periodEnd);

        if ($days['total'] <= 0) {
            return 0.0;
        }

        return round($days['remaining'] / $days['total'], 6);
    }

    /**
     * Calculate prorata charge and credit amounts for a subscription change.
     *
     * This is the single source of truth for prorata calculations. Both
     * UpdateSubscription and PreviewService delegate here.
     *
     * **Same interval** (e.g. monthly → monthly with different plan):
     *   - Upgrade (newNet > currentNet): charge = (newNet - currentNet) * factor
     *   - Downgrade (newNet < currentNet): credit = (currentNet - newNet) * factor
     *
     * **Interval change** (e.g. yearly → monthly):
     *   - The old and new amounts cover different period lengths and cannot be
     *     compared directly. The unused portion of the current period is credited
     *     in full. The new plan's first payment is collected by Mollie via the
     *     new subscription — no prorata charge is needed.
     *
     * @return array{charge_net: int, credit_net: int, factor: float}
     */
    public static function computeProrata(
        int $currentNet,
        int $newNet,
        bool $intervalChanged,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        $factor = self::prorataFactor($periodStart, $periodEnd);
        $chargeNet = 0;
        $creditNet = 0;

        if ($intervalChanged) {
            // Interval change: the unused portion of the current period is
            // credited against the full price of the new plan.
            //
            // Upgrade (newNet > unusedCredit): charge the difference via
            //   Mollie as a single payment with itemised line items showing
            //   the new plan price and the old-plan credit as a deduction.
            //   creditNet = unusedCredit (for invoice line-item breakdown).
            //
            // Downgrade (newNet <= unusedCredit): refund the net difference
            //   via Mollie. The new subscription's first payment is collected
            //   separately by Mollie when the new subscription is created.
            //   creditNet = unusedCredit - newNet (actual refund amount).
            $unusedCredit = (int) round($currentNet * $factor);
            $netDue = $newNet - $unusedCredit;
            if ($netDue > 0) {
                $chargeNet = $netDue;
                $creditNet = $unusedCredit;
            } else {
                $creditNet = abs($netDue);
            }
        } else {
            $diff = $newNet - $currentNet;
            if ($diff > 0) {
                $chargeNet = (int) round($diff * $factor);
            } elseif ($diff < 0) {
                $creditNet = (int) round(-$diff * $factor);
            }
        }

        return [
            'charge_net' => $chargeNet,
            'credit_net' => $creditNet,
            'factor' => $factor,
        ];
    }

    /**
     * Whether a plan change is an upgrade based on base plan prices.
     *
     * Compares base prices only (excluding seats, addons, coupons) so
     * that a plan downgrade with many extra seats is still classified
     * as a downgrade.
     */
    public static function isUpgrade(
        SubscriptionCatalogInterface $catalog,
        string $currentPlanCode,
        string $currentInterval,
        string $newPlanCode,
        string $newInterval,
    ): bool {
        $currentBase = $catalog->basePriceNet($currentPlanCode, $currentInterval);
        $newBase = $catalog->basePriceNet($newPlanCode, $newInterval);

        return $newBase > $currentBase;
    }

    /**
     * Whether a plan change is a downgrade based on base plan prices.
     */
    public static function isDowngrade(
        SubscriptionCatalogInterface $catalog,
        string $currentPlanCode,
        string $currentInterval,
        string $newPlanCode,
        string $newInterval,
    ): bool {
        $currentBase = $catalog->basePriceNet($currentPlanCode, $currentInterval);
        $newBase = $catalog->basePriceNet($newPlanCode, $newInterval);

        return $newBase < $currentBase;
    }

    /**
     * Compute prorated usage excess when a plan change occurs mid-period.
     *
     * The elapsed fraction of the current period determines how much of the
     * old plan's quota the billable was entitled to use so far. If the
     * current wallet balance is lower than that prorated quota, the
     * difference is excess that must be settled (offset against the new
     * plan's quota or charged as overage).
     *
     * Works identically for rollover and non-rollover modes:
     * - Without rollover: balance ≤ oldIncluded, excess arises when more
     *   was consumed than the prorated entitlement.
     * - With rollover: balance may exceed oldIncluded (carried credits);
     *   those are respected and excess only arises when even carried
     *   credits are exhausted.
     *
     * @return array{prorated_old_quota: int, current_balance: int, excess: int, elapsed_fraction: float}
     */
    public static function computeUsageOverageForPlanChange(
        int $oldIncluded,
        int $currentBalance,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        $factor = self::prorataFactor($periodStart, $periodEnd);
        $elapsedFraction = round(1.0 - $factor, 6);
        $proratedOldQuota = (int) round($oldIncluded * $elapsedFraction);
        $excess = max(0, $proratedOldQuota - $currentBalance);

        return [
            'prorated_old_quota' => $proratedOldQuota,
            'current_balance' => $currentBalance,
            'excess' => $excess,
            'elapsed_fraction' => $elapsedFraction,
        ];
    }
}
