<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;

/**
 * Twelve monthly periods of an ordinary card subscription, night by night.
 *
 * Card means: the overage payment the sweep creates the night before a renewal settles at
 * once, and the renewal is `paid` on Mollie's cycle date itself.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

it('books twelve periods of mixed usage exactly once each', function (string $nights): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    // Per period: Tokens used (quota 100, no rollover), SMS used (quota 50, rollover).
    $usage = [
        [30, 10], [100, 50], [137, 80], [0, 0], [250, 120], [100, 0],
        [99, 49], [101, 51], [100, 200], [1, 1], [500, 10], [60, 60],
    ];

    // The books kept by hand, to check the package's against.
    $smsBalance = 50;
    $purchased = 0;
    $expectedOverageNet = 0;
    $expectedCharges = 0;
    $expectedBilled = [];

    foreach ($usage as $period => [$tokens, $sms]) {
        $periodStart = $start->copy()->addMonthsNoOverflow($period);
        $periodEnd = $start->copy()->addMonthsNoOverflow($period + 1);

        // A 500-unit pack bought early in the third period.
        if ($period === 2) {
            $run->travelTo($periodStart->copy()->addDays(2));
            $run->purchase('Tokens', 500);
            $purchased = 500;
        }

        for ($day = $periodStart->copy()->addDay()->setTime(1, 0); $day->lessThan($periodEnd); $day->addDay()) {
            $run->travelTo($day);

            // Usage lands mid-period, before that night's pass.
            if ($day->isSameDay($periodStart->copy()->addDays(14))) {
                $run->use('Tokens', $tokens);
                $run->use('SMS', $sms);
            }

            $daysToBoundary = (int) $day->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay());
            $daysSinceStart = (int) $periodStart->copy()->startOfDay()->diffInDays($day->copy()->startOfDay());

            $runs = match ($nights) {
                // The one night the sweep is keyed on never happens.
                'eve missed' => $daysToBoundary === 1 ? 0 : 1,
                // An outage across every boundary: the two nights before it and the one after.
                'three nights missed' => ($daysToBoundary <= 2 || $daysSinceStart === 1) ? 0 : 1,
                // A second scheduler, a manual run, a re-delivered job.
                'every night twice' => 2,
                default => 1,
            };

            for ($i = 0; $i < $runs; $i++) {
                $run->night();
            }

            // Card: whatever the pass charged is paid within the minute.
            $run->settleOpenOverage();
        }

        // By hand: plan quota first, then purchased units, then overage.
        $tokenOverage = max(0, $tokens - 100 - $purchased);
        $purchased -= max(0, min($purchased, $tokens - 100));
        $smsBalance -= $sms;
        $smsOverage = max(0, -$smsBalance);
        $smsBalance = max(0, $smsBalance);

        if ($tokenOverage + $smsOverage > 0) {
            $expectedCharges++;
            $expectedOverageNet += $tokenOverage * 10 + $smsOverage * 15;
        }
        if ($tokenOverage > 0) {
            $expectedBilled['Tokens@10'] = ($expectedBilled['Tokens@10'] ?? 0) + $tokenOverage;
        }
        if ($smsOverage > 0) {
            $expectedBilled['SMS@15'] = ($expectedBilled['SMS@15'] ?? 0) + $smsOverage;
        }

        // Mollie collects the renewal on the cycle date; a card is paid at once.
        $run->travelTo($periodEnd->copy()->addMinutes(3));
        $run->renew($periodEnd);
        $run->settleOpenOverage();

        $smsBalance += 50;
        $label = 'after period '.($period + 1);
        $billable = $run->fresh();

        // The anchor is the cycle date, to the second — twelve times over.
        expect($billable->subscription_period_starts_at->toIso8601String())->toBe($periodEnd->toIso8601String(), $label);
        expect($billable->subscription_status)->toBe(SubscriptionStatus::Active, $label);

        // Exactly one quota credit per renewal, of exactly the quota (plus surviving pack).
        expect($run->count('Tokens', 'period_credit'))->toBe($period + 1, $label);
        expect($run->count('SMS', 'subscription_renewal_rollover'))->toBe($period + 1, $label);

        // Non-rollover: quota + what is left of the pack. Rollover: carried.
        expect($run->balance('Tokens'))->toBe(100 + $purchased, $label);
        expect($run->purchased('Tokens'))->toBe($purchased, $label);
        expect($run->balance('SMS'))->toBe($smsBalance, $label);

        // Charged once, at the plan's price, and nothing left open.
        expect($run->paymentRequests)->toBe($expectedCharges, $label);
        expect($run->overageInvoicedNet())->toBe($expectedOverageNet, $label);
        expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage', $label);
    }

    expect($run->units('SMS', 'subscription_renewal_rollover'))->toBe(12 * 50);
    expect($run->billedUnits())->toBe(collect($expectedBilled)->sortKeys()->all());
    expect($run->invoiceCount('subscription'))->toBe(12);
    expect($run->invoiceCount('overage'))->toBe($expectedCharges);
})->with([
    'every night once' => 'once',
    'eve missed' => 'eve missed',
    'three nights missed' => 'three nights missed',
    'every night twice' => 'every night twice',
]);
