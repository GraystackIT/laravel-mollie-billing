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

it('clamps a month-end anchor once and then keeps that day', function (): void {
    // Known limit, pinned so it cannot get worse: a Local period that starts on the 31st
    // is clamped to the 28th in February and stays on the 28th from then on. It does not
    // lose a further day per period.
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-31T09:00:00Z');
    usageLongRunLocalBillable($run, $start);

    $anchors = [];

    for ($day = $start->copy()->addDay()->setTime(1, 0); $day->lessThan(Carbon::parse('2026-07-01')); $day->addDay()) {
        $run->travelTo($day);
        $run->night();

        $anchor = $run->fresh()->subscription_period_starts_at->toDateString();
        if (end($anchors) !== $anchor) {
            $anchors[] = $anchor;
        }
    }

    expect($anchors)->toBe(['2026-01-31', '2026-02-28', '2026-03-28', '2026-04-28', '2026-05-28', '2026-06-28']);
    expect($run->count('Tokens', 'period_credit'))->toBe(5);
});

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
