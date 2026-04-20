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

        $remainingDays = now()->startOfDay()->diffInDays($end, false);

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
}
