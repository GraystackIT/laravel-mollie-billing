<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;

/**
 * The day of the month a Mollie subscription is charged on — kept through the months
 * that do not have it.
 *
 * A customer who subscribed on the 31st is charged on the 31st, and on the last day of a
 * month that is shorter: 31 Jan → 28 Feb → 31 Mar → 30 Apr → 31 May. One month after a
 * charge (`BillingTime::addInterval()`, no overflow) cannot express that — from 28 Feb it
 * is 28 Mar, and from then on the 28th for good. The period anchor
 * (`subscription_period_starts_at`) is the charge itself, so the day it started from has
 * to be kept beside it: `subscription_meta.billing_anchor = {day, from}`.
 *
 * `from` is the period start the day was recorded for. The day is honoured only while
 * the period still starts there — the renewal handler writes both together, and the
 * nightly turn-over of a deferred period carries it on. Anything else that starts a
 * period (a plan or interval change, an upgrade, a checkout) starts one of its own, and
 * the next renewal derives the day afresh.
 *
 * Mollie is not trusted with it: after a short month Mollie may count on from the charge
 * (28 Mar) or from its start date (31 Mar), which is not documented and is measured by
 * the sandbox canary. The renewal handler sends the date itself wherever the two can
 * differ (`SubscriptionPaymentHandler::pinNextChargeOnAnchor()`).
 */
final class BillingAnchor
{
    public const META_KEY = 'billing_anchor';

    /** The anchor day honoured for the billable's current period, or null. */
    public static function day(Billable $billable): ?int
    {
        $stored = $billable->getBillingSubscriptionMeta()[self::META_KEY] ?? null;
        $start = $billable->getBillingPeriodStartsAt();

        if (! is_array($stored) || $start === null) {
            return null;
        }

        $day = $stored['day'] ?? null;
        $from = $stored['from'] ?? null;

        if (! is_int($day) || $day < 1 || $day > 31 || ! is_string($from) || $from === '') {
            return null;
        }

        try {
            return Carbon::parse($from)->getTimestamp() === Carbon::instance($start)->getTimestamp() ? $day : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One interval after `$start`, on `$day` where the month has it and on its last day
     * where it does not. Without a day it is `BillingTime::addInterval()`.
     */
    public static function next(CarbonInterface $start, ?string $interval, ?int $day): CarbonImmutable
    {
        $next = BillingTime::addInterval($start, $interval);

        if ($day === null) {
            return $next;
        }

        return $next->setDay(min($day, $next->daysInMonth));
    }

    /**
     * `$periods` intervals after `$from`, every step on `$day` (`next()` repeated).
     *
     * `$day` is honoured only where `$from` itself lies on it — on the day, or on the last
     * day of a month too short for it (28 February for the 31st). A date off that day (a
     * charge a period extension moved) has a cycle of its own day, and is counted on it.
     * Without a day the cycle runs on `$from`'s own day: 31 January + 2 months is
     * 31 March, not 28 March.
     */
    public static function advance(CarbonInterface $from, ?string $interval, int $periods, ?int $day = null): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from);
        $day = self::dayOf($at, $day);

        for ($i = 0; $i < max(0, $periods); $i++) {
            $at = self::next($at, $interval, $day);
        }

        return $at;
    }

    /**
     * The first date of the cycle that runs from `$from` (on `$day`, `$from` itself
     * included) whose day begins at or after `$end`.
     *
     * The first charge a discount ending at `$end` no longer covers. A renewal is
     * discounted when the discount is still valid at the start of the charge's day
     * (`SubscriptionPaymentHandler` asks on that day, because Mollie creates the charge
     * at an hour nobody controls), so a charge on the day the discount ends at noon is
     * still covered, and the one after it is the first that is not. Never the day after
     * the end: that day is in the middle of a period, and a charge there moves the
     * subscription's billing day for good.
     */
    public static function firstOnOrAfter(CarbonInterface $from, ?string $interval, ?int $day, CarbonInterface $end): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from);
        $day = self::dayOf($at, $day);
        $end = CarbonImmutable::instance($end)->utc();

        // Bounded: 1200 monthly steps are a century.
        for ($i = 0; $i < 1200 && $at->utc()->startOfDay()->lessThan($end); $i++) {
            $at = self::next($at, $interval, $day);
        }

        return $at;
    }

    private static function dayOf(CarbonInterface $at, ?int $day): int
    {
        return $day !== null && $day >= 1 && $day <= 31 && $at->day === min($day, $at->daysInMonth)
            ? $day
            : $at->day;
    }

    /**
     * The anchor day of the period a charge on `$cycle` begins.
     *
     * The charge's own day — unless it fell on the last day of a month that is shorter
     * than the day the previous period ran on, and that day leads exactly to this charge:
     * then the charge is that day, shortened by the month. The day the previous period
     * ran on is its recorded anchor, or — for the first renewal, before anything is
     * recorded — the day it started (the signup on 31 January, whose first charge is
     * 28 February).
     */
    public static function forCycle(Billable $billable, ?CarbonInterface $previousStart, CarbonInterface $cycle, string $interval): int
    {
        $charged = CarbonImmutable::instance($cycle)->utc();

        if ($previousStart === null || $charged->day !== $charged->daysInMonth) {
            return $charged->day;
        }

        $previous = CarbonImmutable::instance($previousStart)->utc();

        foreach ([self::day($billable), $previous->day] as $candidate) {
            if ($candidate !== null
                && $candidate > $charged->day
                && self::next($previous, $interval, $candidate)->toDateString() === $charged->toDateString()
            ) {
                return $candidate;
            }
        }

        // The first charge after a trial: its cycle runs on the trial end's day, and when a
        // fully covering code deferred that charge by N periods, nothing recorded the day —
        // the trial's period started at the signup and is not turned over while it waits.
        // A trial ending on 31 January under a code for one period is first charged on
        // 28 February, and is on the 31st from then on.
        $trialEnd = $billable->getBillingTrialEndsAt();

        if ($trialEnd !== null) {
            $trialEnd = CarbonImmutable::instance($trialEnd)->utc();

            if ($trialEnd->day > $charged->day
                && $previous->lessThanOrEqualTo($trialEnd)
                && $trialEnd->lessThan($charged)
                && self::firstOnOrAfter($trialEnd, $interval, $trialEnd->day, $charged->startOfDay())->toDateString() === $charged->toDateString()
            ) {
                return $trialEnd->day;
            }
        }

        return $charged->day;
    }

    /**
     * The meta with the anchor recorded for a period starting at `$from`.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function record(array $meta, int $day, CarbonInterface $from): array
    {
        $meta[self::META_KEY] = [
            'day' => $day,
            'from' => Carbon::instance($from)->utc()->toIso8601String(),
        ];

        return $meta;
    }
}
