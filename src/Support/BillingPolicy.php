<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonInterface;

class BillingPolicy
{
    /**
     * Remaining fraction of the period from now to periodEnd, in [0, 1].
     *
     * Uses whole calendar days (startOfDay) so that a change made on the
     * same day as the period start yields a factor of exactly 1.0. The
     * current day counts as a full remaining day — billing granularity
     * is per-day, not per-second.
     */
    public static function prorataFactor(CarbonInterface $periodStart, CarbonInterface $periodEnd): float
    {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->startOfDay();
        $totalDays = $start->diffInDays($end);

        if ($totalDays <= 0) {
            return 0.0;
        }

        $remainingDays = now($end->getTimezone())->startOfDay()->diffInDays($end, false);

        return round(max(0, $remainingDays) / $totalDays, 6);
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
            // Interval change: credit unused portion of current period.
            $creditNet = (int) round($currentNet * $factor);
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
