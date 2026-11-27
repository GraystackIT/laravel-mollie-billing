<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;

/**
 * Local subscriptions have no renewal webhook: the nightly pass is their renewal. These
 * runs walk a free plan and an access grant through their periods night by night.
 */
beforeEach(function (): void {
    UsageLongRunHarness::definePlan('free', [
        'base_price_net' => 0,
        'usage_overage_prices' => [],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

function usageLongRunLocalBillable(UsageLongRunHarness $run, Carbon $start, array $attributes = []): void
{
    $run->travelTo($start);
    $run->createBillable(array_merge([
        'mollie_customer_id' => null,
        'mollie_mandate_id' => null,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
        'subscription_period_starts_at' => $start,
    ], $attributes));
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);
}

it('refills a free plan once per period for a year and never charges it', function (string $nights): void {
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-10T09:00:00Z');
    usageLongRunLocalBillable($run, $start);

    $usage = [[30, 10], [100, 50], [100, 0], [0, 0], [60, 120], [100, 30], [99, 49], [100, 51], [1, 1], [100, 10], [50, 60], [70, 70]];

    $smsBalance = 50;
    $purchased = 0;
    $refills = 0;

    $boundary = $start->copy();

    foreach ($usage as $period => [$tokens, $sms]) {
        $periodStart = $boundary->copy();
        // The pass moves a Local anchor to midnight of the boundary day.
        $boundary = $start->copy()->addMonthsNoOverflow($period + 1)->startOfDay();

        if ($period === 2) {
            $run->travelTo($periodStart->copy()->addDays(2));
            $run->purchase('Tokens', 300);
            $purchased = 300;
        }

        for ($day = $periodStart->copy()->addDay()->setTime(1, 0); $day->lessThan($boundary); $day->addDay()) {
            $run->travelTo($day);

            if ($day->isSameDay($periodStart->copy()->addDays(14))) {
                // Hard cap: what does not fit is refused, not booked as debt.
                $tokenRoom = 100 + $purchased;
                $booked = min($tokens, $tokenRoom);
                $run->use('Tokens', $booked);
                $purchased -= max(0, min($purchased, $booked - 100));

                $smsBooked = min($sms, $smsBalance);
                if ($smsBooked > 0) {
                    $run->use('SMS', $smsBooked);
                }
                $smsBalance -= $smsBooked;

                expect(fn () => $run->use('SMS', $smsBalance + 1))->toThrow(UsageLimitExceededException::class);
            }

            for ($i = 0; $i < ($nights === 'twice' ? 2 : 1); $i++) {
                $run->night();
            }
        }

        // The night before the boundary did the refill.
        $refills++;
        $smsBalance += 50;
        $label = 'after period '.($period + 1);
        $billable = $run->fresh();

        expect($billable->subscription_period_starts_at->toIso8601String())->toBe($boundary->toIso8601String(), $label);
        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active, $label);
        expect($run->count('Tokens', 'period_credit'))->toBe($refills, $label);
        expect($run->count('SMS', 'subscription_renewal_rollover'))->toBe($refills, $label);
        expect($run->balance('Tokens'))->toBe(100 + $purchased, $label);
        expect($run->purchased('Tokens'))->toBe($purchased, $label);
        expect($run->balance('SMS'))->toBe($smsBalance, $label);
    }

    // A free plan never reaches Mollie.
    expect($run->requests)->toBe([]);
    expect($run->units('SMS', 'subscription_renewal_rollover'))->toBe(12 * 50);
})->with(['every night once' => 'once', 'every night twice' => 'twice']);

it('keeps a Local period on its anchor day through the short months', function (string $start, string $until, ?string $endsAt, array $expectedAnchors): void {
    // A free plan, or a gift without extras, started on the 31st: the nightly pass turns
    // the period over on the 31st, and on the last day of a month that has no 31st —
    // 29 February in a leap year, 28 February otherwise — and returns to the 31st after it
    // (BillingAnchor). One refill per period, on the day it begins.
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse($start);
    usageLongRunLocalBillable($run, $start, $endsAt !== null ? ['subscription_ends_at' => Carbon::parse($endsAt)] : []);

    $anchors = [];

    for ($day = $start->copy()->addDay()->setTime(1, 0); $day->lessThan(Carbon::parse($until)); $day->addDay()) {
        $run->travelTo($day);
        $run->night();

        $billable = $run->fresh();
        $anchor = $billable->subscription_period_starts_at->toDateString();

        if (end($anchors) !== $anchor) {
            $anchors[] = $anchor;

            // The period ends where the next one will begin: on the anchor day.
            $next = $expectedAnchors[count($anchors)] ?? null;
            if ($next !== null) {
                expect($billable->nextBillingDate()->toDateString())->toBe($next, 'period from '.$anchor);
            }
        }
    }

    expect($anchors)->toBe($expectedAnchors)
        ->and($run->count('Tokens', 'period_credit'))->toBe(count($expectedAnchors) - 1);
})->with([
    'free plan, through a leap February' => ['2027-12-31T09:00:00Z', '2029-04-02', null,
        ['2027-12-31', '2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30', '2028-05-31', '2028-06-30', '2028-07-31', '2028-08-31', '2028-09-30', '2028-10-31', '2028-11-30', '2028-12-31', '2029-01-31', '2029-02-28', '2029-03-31'],
    ],
    'free plan, the 30th' => ['2026-01-30T09:00:00Z', '2026-07-01', null,
        ['2026-01-30', '2026-02-28', '2026-03-30', '2026-04-30', '2026-05-30', '2026-06-30'],
    ],
    // A gift to 15 May: four boundaries before its end, none after.
    'gift without extras, ending 15 May' => ['2026-12-31T09:00:00Z', '2027-06-30', '2027-05-15T09:00:00Z',
        ['2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'],
    ],
]);

it('gives an access grant one quota per period it covers and nothing after it ends', function (int $grantMonths, int $extraDays, int $expectedRefills): void {
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $endsAt = $start->copy()->addMonthsNoOverflow($grantMonths)->addDays($extraDays);

    usageLongRunLocalBillable($run, $start, ['subscription_ends_at' => $endsAt]);

    for ($day = $start->copy()->addDay()->setTime(1, 0); $day->lessThan($endsAt->copy()->addDays(70)); $day->addDay()) {
        $run->travelTo($day);
        $run->night();

        // The grant is consumed to the last unit every period.
        if ($run->fresh()->subscription_status === SubscriptionStatus::Active && $run->balance('Tokens') > 0) {
            $run->use('Tokens', $run->balance('Tokens'));
        }
    }

    $billable = $run->fresh();

    // Ended, wiped, and not a period more than it was given.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Expired)
        ->and($billable->subscription_plan_code)->toBeNull()
        ->and($run->count('Tokens', 'period_credit'))->toBe($expectedRefills)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe($expectedRefills * 50)
        ->and($run->requests)->toBe([]);
})->with([
    // 2 months + 15 days: periods 1, 2 and a started third → two refills.
    'ends mid-period' => [2, 15, 2],
    // Exactly two months: two periods. The boundary it ends on starts no third.
    'ends on a period boundary' => [2, 0, 1],
    // Ends the day after a boundary: that boundary still starts a (one-day) period.
    'ends a day after a boundary' => [2, 1, 2],
]);

it('keeps renewing a free plan that was resumed after its period turned over during the grace', function (): void {
    // Cancelled on 20 March, a free plan runs a 30-day grace to 19 April — past its
    // 1 April boundary, which the nightly pass rightly does not renew for a subscription
    // that is running out. Resumed on 5 April it used to come back Active on the 1 March
    // period: `nextBillingDate()` stuck on 1 April, which the pass's "renews tomorrow"
    // test never matches again, so the quota was never refilled and every seat, addon or
    // plan change was refused with "wait for the renewal" — for good.
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2027-03-01T00:00:00Z');
    usageLongRunLocalBillable($run, $start);

    $run->travelTo('2027-03-20 10:00:00');
    $run->use('Tokens', 100);
    $run->fresh()->cancelBillingSubscription();

    for ($day = Carbon::parse('2027-03-21 02:00:00'); $day->lessThan(Carbon::parse('2027-04-05 02:00:00')); $day->addDay()) {
        $run->travelTo($day);
        $run->night();
    }

    expect($run->count('Tokens', 'period_credit'))->toBe(0);

    $run->travelTo('2027-04-05 10:00:00');
    $run->fresh()->resubscribeBillingPlan();

    $billable = $run->fresh();

    // Back on the period that is running, with its quota: what the night of 31 March
    // would have done had the subscription not been cancelled then.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at)->toBeNull()
        ->and($billable->subscription_period_starts_at->toIso8601String())->toBe('2027-04-01T00:00:00+00:00')
        ->and($billable->nextBillingDate()->toDateString())->toBe('2027-05-01')
        ->and($run->count('Tokens', 'period_credit'))->toBe(1)
        ->and($run->balance('Tokens'))->toBe(100);

    // And it goes on renewing: the night of 30 April turns it over to 1 May.
    for ($day = Carbon::parse('2027-04-06 02:00:00'); $day->lessThan(Carbon::parse('2027-05-02 02:00:00')); $day->addDay()) {
        $run->travelTo($day);
        $run->night();
    }

    expect($run->fresh()->subscription_period_starts_at->toDateString())->toBe('2027-05-01')
        ->and($run->count('Tokens', 'period_credit'))->toBe(2)
        ->and($run->requests)->toBe([]);
});
