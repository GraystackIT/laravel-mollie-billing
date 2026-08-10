<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * The included quota a trial actually receives.
 *
 * A trial collects no money, so it is credited only the share of the plan's
 * period quota that its own length represents: a 14-day trial on a monthly plan
 * with 100 included units gets 47, not 100. `MandateOnlyPaymentHandler` owns the
 * grant; this class owns the arithmetic, because the portal has to be able to
 * arrive at the same number. It could not, and every trial therefore rendered the
 * un-granted remainder as *consumption* — a wallet credited 10 of a 20-unit plan
 * quota read "10 of 20 remaining" on a subscription minutes old.
 *
 * `ceil` rather than `round`: the fraction belongs to the customer.
 */
final class TrialQuota
{
    /**
     * Days a billing interval is prorated against. Nominal, not calendar: the same
     * trial length must yield the same grant in February as in March.
     */
    public const MONTHLY_INTERVAL_DAYS = 30;

    public const YEARLY_INTERVAL_DAYS = 365;

    public static function intervalDays(?string $interval): int
    {
        return $interval === 'yearly'
            ? self::YEARLY_INTERVAL_DAYS
            : self::MONTHLY_INTERVAL_DAYS;
    }

    /**
     * One usage type's trial share of a period quota.
     */
    public static function prorate(int $included, int $trialDays, ?string $interval): int
    {
        if ($included <= 0 || $trialDays <= 0) {
            return 0;
        }

        $intervalDays = self::intervalDays($interval);

        // A trial longer than the interval it is prorated against is not capped:
        // the customer was promised that many days of the plan, and capping at one
        // period would hand a 60-day trial the quota of 30.
        return (int) ceil($included * $trialDays / $intervalDays);
    }

    /**
     * Every usage type's trial share, keyed by type. Types with nothing included
     * are dropped so callers can hand the result straight to the crediting loop.
     *
     * @param  iterable<string, int|numeric-string>  $includedUsages
     * @return array<string, int>
     */
    public static function forPlan(iterable $includedUsages, int $trialDays, ?string $interval): array
    {
        $quota = [];

        foreach ($includedUsages as $type => $included) {
            $prorated = self::prorate((int) $included, $trialDays, $interval);

            if ($prorated <= 0) {
                continue;
            }

            $quota[(string) $type] = $prorated;
        }

        return $quota;
    }
}
