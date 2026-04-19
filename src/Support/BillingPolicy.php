<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonInterface;

class BillingPolicy
{
    /**
     * Remaining fraction of the period from now to periodEnd, in [0, 1].
     */
    public static function prorataFactor(CarbonInterface $periodStart, CarbonInterface $periodEnd): float
    {
        $totalDays = $periodStart->diffInDays($periodEnd);

        if ($totalDays <= 0) {
            return 0.0;
        }

        $remainingDays = now()->diffInDays($periodEnd, false);

        return round(max(0, $remainingDays) / $totalDays, 6);
    }
}
