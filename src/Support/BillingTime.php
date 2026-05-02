<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;

/**
 * Timezone helper for the package.
 *
 * Persistence and all internal computations are pinned to UTC. Display happens
 * in two flavors: portal views convert into the billable's display timezone,
 * admin views render in UTC so staff sees exactly the values stored in the
 * database and surfaced in logs / Mollie events.
 *
 * See docs/timezone.md.
 */
class BillingTime
{
    /**
     * UTC-pinned "now" for all internal computations and writes. Use instead
     * of `now()` so the package is robust against an `app.timezone` that
     * differs from UTC.
     */
    public static function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * Convert a datetime into the display timezone for the given billable.
     * Without a billable, falls back to the `mollie-billing.billing_timezone`
     * config value (used in tenant-less portal contexts). Admin views should
     * use {@see displayUtc()} instead so they always render the raw UTC value.
     *
     * Returns a clone so chained `->translatedFormat()` / `->diffForHumans()`
     * does not mutate the source.
     */
    public static function display(?CarbonInterface $dt, ?Billable $billable = null): ?CarbonInterface
    {
        if ($dt === null) {
            return null;
        }

        $tz = $billable?->getBillingTimezone() ?? self::configuredTimezone();

        return $dt->copy()->setTimezone($tz);
    }

    /**
     * Force UTC display. Used by admin views so the staff member sees exactly
     * the values stored in the database and surfaced in logs / Mollie events.
     */
    public static function displayUtc(?CarbonInterface $dt): ?CarbonInterface
    {
        return $dt?->copy()->setTimezone('UTC');
    }

    private static function configuredTimezone(): string
    {
        $tz = config('mollie-billing.billing_timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }
}
