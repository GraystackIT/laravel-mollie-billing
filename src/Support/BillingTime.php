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
     * A billing DAY for the portal: a charge date, a period boundary, the end of a
     * subscription, a trial or a gift. Shown as the calendar day the package computes it
     * on — in UTC — whatever the billable's timezone, and never as an instant converted
     * into it.
     *
     * Mollie charges a subscription on a date (`nextPaymentDate`), at an hour nobody
     * chooses (measured: 00:28 UTC), and the package compares these values as days. The
     * instant behind them carries an hour that is incidental — the hour the first payment
     * was made, or the hour Mollie's scheduler happened to run. Converted, the same charge
     * read "10 Feb" before the first renewal (anchored on a payment at 10:00 UTC) and
     * "9 Mar" after it (anchored on Mollie's 00:28) for a customer in New York; next to
     * "plan free until 10 Apr" the portal showed a full-price charge on 9 April. A day is
     * not an instant: events that happened at a moment (an invoice, a usage booking, a
     * purchase) keep {@see display()}.
     */
    public static function billingDay(?CarbonInterface $dt): ?CarbonInterface
    {
        return $dt?->copy()->setTimezone('UTC');
    }

    /**
     * One billing interval after `$at`, clamped to the end of a shorter month.
     *
     * `addMonth()` overflows: 31 January plus one month is 3 March, not 28 February. That is
     * PHP's calendar arithmetic and it is wrong for billing in three ways at once.
     *
     * Mollie charges a monthly subscription on the last day of a month that has no 31st, so
     * a customer whose period starts on the 31st was told their next charge was 3 March while
     * Mollie collected on 28 February — a three-day gap in the wrong direction. From that
     * follow the two that cost money: `PrepareUsageOverageJob` charges a period's overage on
     * the day *before* `nextBillingDate()`, which by then is after Mollie has renewed and
     * moved the anchor, so that period's overage is never charged separately at all; and the
     * prorata window is 31 days long for a 28-day period, so a mid-period upgrade of 3000 net
     * on 14 February was priced at 17/31 (1645) instead of 14/28 (1500) — and a downgrade
     * over-credits by the same arithmetic.
     *
     * Every "one interval later" in the package goes through here so the three cannot drift
     * apart again.
     */
    public static function addInterval(CarbonInterface $at, ?string $interval): CarbonImmutable
    {
        $next = CarbonImmutable::instance($at);

        return $interval === 'yearly'
            ? $next->addYearNoOverflow()
            : $next->addMonthNoOverflow();
    }

    /**
     * One billing interval earlier, clamped the same way {@see addInterval()} clamps forward.
     *
     * Month arithmetic is not invertible — `31 March − 1 month + 1 month` is 1 April with
     * plain Carbon, because `subMonth()` overflows 31 February down to 3 March and
     * `addMonthNoOverflow()` does not undo it. Anywhere a period anchor is derived *backwards*
     * from a known charge date, that asymmetry puts `nextBillingDate()` up to three days past
     * the date Mollie will actually charge on, and `PrepareUsageOverageJob`'s "renews
     * tomorrow" window then matches neither the real day before nor the drifted one — so that
     * period's overage is never collected separately at all.
     */
    public static function subInterval(CarbonInterface $at, ?string $interval): CarbonImmutable
    {
        $previous = CarbonImmutable::instance($at);

        return $interval === 'yearly'
            ? $previous->subYearNoOverflow()
            : $previous->subMonthNoOverflow();
    }

    /**
     * The later of two moments, compared in UTC.
     *
     * Both are normalised through toUtc() first, so a CarbonImmutable and an
     * offset-carrying string compare on the same footing — `max()` on mixed
     * datetime types compares whatever the objects cast to and would pick by
     * wall-clock text rather than by instant.
     */
    public static function latest(
        \DateTimeInterface|string $a,
        \DateTimeInterface|string $b,
    ): \Carbon\Carbon {
        $first = self::toUtc($a);
        $second = self::toUtc($b);

        return $first->greaterThanOrEqualTo($second) ? $first : $second;
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
