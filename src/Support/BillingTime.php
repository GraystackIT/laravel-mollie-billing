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

    /**
     * Convert a heterogeneous datetime input to a UTC Carbon for internal use.
     *
     * Accepts a `CarbonInterface` (already-typed values from a UtcDatetime cast),
     * any `DateTimeInterface`, an ISO8601 string with an offset, or a plain
     * `Y-m-d H:i:s` string. The branching is critical: a `(string)`-cast on a
     * CarbonImmutable produces an offset-less `Y-m-d H:i:s`, which `Carbon::parse()`
     * would re-interpret in `app.timezone` and silently shift the value. By keeping
     * already-typed values in their object form and only invoking `Carbon::parse()`
     * on raw strings, this helper is correct under any `app.timezone`.
     *
     * Plain strings without an offset are interpreted as UTC.
     */
    public static function toUtc(\DateTimeInterface|string $value): \Carbon\Carbon
    {
        if ($value instanceof CarbonInterface) {
            return \Carbon\Carbon::instance($value)->setTimezone('UTC');
        }

        if ($value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($value)->setTimezone('UTC');
        }

        // String: if it already carries an offset, parse() honors it; if not,
        // interpret it strictly as UTC.
        if (preg_match('/[+-]\d{2}:?\d{2}$|Z$/', $value) === 1) {
            return \Carbon\Carbon::parse($value)->setTimezone('UTC');
        }

        return \Carbon\Carbon::parse($value, 'UTC');
    }

    private static function configuredTimezone(): string
    {
        $tz = config('mollie-billing.billing_timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }
}
