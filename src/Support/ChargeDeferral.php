<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Services\Billing\CouponService;

/**
 * When Mollie will next collect money, as opposed to when the billing period ends.
 *
 * The two are the same date for an ordinary subscription, which is why the package got
 * by with one value (`nextBillingDate()`) for so long. They part ways when a discount
 * covers the whole recurring amount: Mollie cannot charge 0, so the subscription keeps
 * its full price and its start date is pushed past the discount instead. From then on
 * the *period* still turns over every interval — quota is owed, prorata windows are
 * measured, the customer is told a renewal date — while the *charge* sits months away.
 *
 * That deferred date used to exist at Mollie only. Everything that later touched the
 * charge date therefore worked from the period end and got it wrong in both directions:
 *
 *  - a seat or addon bought under a 100 % coupon PATCHed the new amount without a start
 *    date, so Mollie kept the deferred one and the purchase was free until the coupon
 *    ended;
 *  - a period extension computed `period end + N days`, which is *earlier* than the
 *    deferred date, and Mollie then collected the full plan price while the coupon was
 *    still running.
 *
 * `charge_deferred_until` is the single stored answer. It is written wherever the
 * package defers the charge and read by everything that sets or reasons about it.
 */
final class ChargeDeferral
{
    public const META_KEY = 'charge_deferred_until';

    /**
     * The date Mollie's next charge is deferred to, or null when the charge follows the
     * regular cycle. Only a future date counts — a deferral that has passed is history.
     */
    public static function until(Billable $billable): ?Carbon
    {
        if ($billable->getBillingSubscriptionSource() !== SubscriptionSource::Mollie->value) {
            return null;
        }

        $stored = $billable->getBillingSubscriptionMeta()[self::META_KEY] ?? null;

        if (is_string($stored) && $stored !== '') {
            try {
                $parsed = Carbon::parse($stored)->setTimezone('UTC');

                return $parsed->isFuture() ? $parsed : null;
            } catch (\Throwable) {
                // fall through to the derivation below
            }
        }

        return self::derivedFromCoupon($billable);
    }

    /**
     * Subscriptions deferred before the value was stored: the date is `valid_until + 1
     * day` of a recurring coupon that still covers the whole recurring amount.
     */
    private static function derivedFromCoupon(Billable $billable): ?Carbon
    {
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

        if (! is_array($marker) || empty($marker['valid_until'])) {
            return null;
        }

        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? '');

        if ($planCode === '' || $interval === '') {
            return null;
        }

        try {
            $deferred = Carbon::parse((string) $marker['valid_until'])->setTimezone('UTC')->addDay();
        } catch (\Throwable) {
            return null;
        }

        if (! $deferred->isFuture()) {
            return null;
        }

        $net = SubscriptionAmount::net(
            app(SubscriptionCatalogInterface::class),
            $billable,
            $planCode,
            $interval,
            $billable->getBillingSeatCount(),
            $billable->getActiveBillingAddonCodes(),
        );

        if ($net <= 0) {
            return null;
        }

        return app(CouponService::class)->computeMarkerDiscount($billable, $net) >= $net
            ? $deferred
            : null;
    }

    /**
     * The date money is next collected: the deferral if there is one, otherwise the end
     * of the current period.
     */
    public static function nextChargeDate(Billable $billable): ?CarbonInterface
    {
        return self::until($billable) ?? $billable->nextBillingDate();
    }

    /**
     * Where the charge sits when nothing defers it — the date a deferral that ends early
     * returns the charge to.
     *
     * Not simply the next period boundary. A trial's first charge is its end, and a
     * period extension has moved the charge behind the boundary on purpose
     * (`next_charge_date_override`, which `nextBillingDate()` carries). Returning to the
     * bare boundary pulled the charge in front of an extension the customer had been
     * given, and moved a trial's first charge from its end to a month after signup.
     */
    public static function undeferredNextChargeDate(Billable $billable): ?Carbon
    {
        $trialEndsAt = $billable->getBillingTrialEndsAt();

        if (
            $billable->getBillingSubscriptionStatus() === \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Trial
            && $trialEndsAt !== null
            && $trialEndsAt->isFuture()
        ) {
            return Carbon::instance($trialEndsAt)->setTimezone('UTC');
        }

        $next = $billable->nextBillingDate();

        if ($next !== null && $next->isFuture()) {
            return Carbon::instance($next)->setTimezone('UTC');
        }

        return self::regularNextBillingDate($billable);
    }

    /**
     * The next period boundary on the regular cycle that still lies ahead.
     *
     * This is where a charge returns to when a deferral ends early. It is rolled forward
     * rather than read straight off the anchor, because a subscription deferred before
     * the nightly pass learned to turn its period over can carry an anchor that is
     * several intervals old — and a start date in the past makes Mollie charge at once.
     */
    public static function regularNextBillingDate(Billable $billable): ?Carbon
    {
        $start = $billable->getBillingPeriodStartsAt();
        $interval = $billable->getBillingSubscriptionInterval();

        if ($start === null || $interval === null) {
            return null;
        }

        $now = BillingTime::nowUtc();
        $next = BillingTime::addInterval($start, $interval);

        // Bounded: a monthly anchor ten years stale is 120 steps.
        for ($i = 0; $i < 600 && $next->lessThanOrEqualTo($now); $i++) {
            $next = BillingTime::addInterval($next, $interval);
        }

        return Carbon::instance($next)->setTimezone('UTC');
    }
}
