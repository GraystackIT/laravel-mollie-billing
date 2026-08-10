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

    /**
     * The `subscription_meta.trial_quota` entry recording what a trial was granted.
     *
     * Recorded rather than re-derived: the configured trial length can change under a
     * running trial, `extendBillingTrialUntil()` moves the end date while crediting
     * nothing, and a plan change mid-trial re-grants against a different plan. The plan
     * and interval travel with it so a reader can tell whether it still applies.
     *
     * @param  array<string, int>  $usages
     * @return array<string, mixed>
     */
    public static function marker(string $planCode, ?string $interval, int $trialDays, array $usages): array
    {
        return [
            'granted_at' => BillingTime::nowUtc()->toIso8601String(),
            'plan_code' => $planCode,
            'interval' => $interval,
            'trial_days' => $trialDays,
            'usages' => $usages,
        ];
    }

    /**
     * The recorded grant, but only while it still describes the given plan and interval.
     *
     * Null for a marker that is absent or stale — the caller then falls back to
     * re-deriving from the catalog's configured trial length, which is all a trial
     * granted before the marker existed can offer.
     *
     * @param  array<string, mixed>  $meta  the billable's `subscription_meta`
     * @return array<string, int>|null
     */
    public static function grantedFromMeta(array $meta, ?string $planCode, ?string $interval): ?array
    {
        $marker = $meta['trial_quota'] ?? null;

        if (! is_array($marker)
            || ($marker['plan_code'] ?? null) !== $planCode
            || ($marker['interval'] ?? null) !== $interval
            || ! is_array($marker['usages'] ?? null)
        ) {
            return null;
        }

        return array_map(static fn ($units): int => (int) $units, $marker['usages']);
    }

    /**
     * The trial length the recorded grant was made for, or null when there is none.
     *
     * The catalog's current `trial_days` is the fallback, and it is not the same
     * question: a plan whose configured trial was shortened after this trial started
     * would re-grant against the shorter one.
     *
     * @param  array<string, mixed>  $meta  the billable's `subscription_meta`
     */
    public static function grantedTrialDays(array $meta): ?int
    {
        $days = ($meta['trial_quota'] ?? null)['trial_days'] ?? null;

        return is_int($days) || (is_string($days) && $days !== '') ? (int) $days : null;
    }
}
