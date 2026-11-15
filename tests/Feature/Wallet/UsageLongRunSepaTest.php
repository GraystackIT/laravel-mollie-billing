<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;

/**
 * Twelve monthly periods on SEPA Direct Debit.
 *
 * Mollie collects on a fixed cycle date; `paid` arrives a varying number of days later,
 * and that is what the period anchor follows. An overage payment takes three days to
 * settle, so it is regularly still open when the renewal lands. Whichever of the two —
 * the sweep or the renewal — gets to the period's overage first, it is billed once.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

it('bills every period once whatever order sweep, renewal and settlement arrive in', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $cycle = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($cycle);
    $run->createBillable(['subscription_period_starts_at' => $cycle]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    // Days between Mollie's collection and `paid`, per renewal. Shrinking lags put the
    // renewal ahead of the sweep night; growing ones put the sweep first.
    $lags = [5, 2, 3, 3, 6, 1, 4, 2, 5, 3, 7, 1];
    // Per cycle month, used 14 days after the cycle date: [Tokens, SMS].
    $usage = [
        [30, 10], [137, 80], [100, 50], [137, 0], [250, 120], [0, 0],
        [101, 49], [100, 200], [99, 51], [1, 1], [500, 10], [60, 60],
    ];
    // In the fourth month 20 more Tokens are used an hour before the renewal, while the
    // sweep's charge for the first 37 is still settling (lags 3 → 3).
    $lateMonth = 3;
    $lateTokens = 20;

    $events = [];
    foreach ($usage as $month => [$tokens, $sms]) {
        $cycleDate = $cycle->copy()->addMonthsNoOverflow($month);
        $events[$cycleDate->copy()->addDays(14)->toDateString()]['use'] = [$tokens, $sms];

        $renewal = $cycle->copy()->addMonthsNoOverflow($month + 1)->addDays($lags[$month]);
        $events[$renewal->toDateString()]['renew'] = $renewal;
        $events[$renewal->copy()->addDays(4)->toDateString()]['check'] = $month;

        if ($month === $lateMonth) {
            $events[$renewal->toDateString()]['late'] = $lateTokens;
        }
    }

    // By hand.
    $tokensAvailable = 100;
    $smsBalance = 50;
    $billed = ['SMS@15' => 0, 'Tokens@10' => 0];
    $charges = 0;
    $settleOn = [];
    $renewals = 0;

    $end = $cycle->copy()->addMonthsNoOverflow(12)->addDays(15);

    for ($day = $cycle->copy()->addDay()->startOfDay(); $day->lessThan($end); $day->addDay()) {
        $today = $events[$day->toDateString()] ?? [];

        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();

        // Any payment created so far settles three days after it was created.
        foreach ($run->overagePayments as $id => $payment) {
            if ($payment['status'] === 'open' && ! isset($settleOn[$id])) {
                $settleOn[$id] = Carbon::parse($payment['created_at'])->addDays(3)->toDateString();
            }
        }

        $run->travelTo($day->copy()->setTime(6, 0));
        foreach ($settleOn as $id => $date) {
            if ($date <= $day->toDateString() && $run->overagePayments[$id]['status'] === 'open') {
                $run->resolveOverage($id);
            }
        }

        if (isset($today['use'])) {
            [$tokens, $sms] = $today['use'];
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use('Tokens', $tokens);
            $run->use('SMS', $sms);

            $tokenOverage = max(0, $tokens - $tokensAvailable);
            $tokensAvailable = 100;
            $smsBalance -= $sms;
            $smsOverage = max(0, -$smsBalance);
            $smsBalance = max(0, $smsBalance);

            $billed['Tokens@10'] += $tokenOverage;
            $billed['SMS@15'] += $smsOverage;
            $charges += ($tokenOverage + $smsOverage) > 0 ? 1 : 0;
        }

        if (isset($today['late'])) {
            $run->travelTo($today['renew']->copy()->subHour());
            $run->use('Tokens', $today['late']);
            // Used on top of a debt whose charge is already in flight: there is one
            // overage slot, so these are not billed — they come out of the next quota.
            $tokensAvailable = 100 - $today['late'];
        }

        if (isset($today['renew'])) {
            $run->travelTo($today['renew']->copy()->addMinutes(2));
            $run->renew($today['renew']);
            $renewals++;
            $smsBalance += 50;

            // The anchor follows the money, as designed — and only the money.
            expect($run->fresh()->subscription_period_starts_at->toIso8601String())
                ->toBe($today['renew']->toIso8601String());

            foreach ($run->overagePayments as $id => $payment) {
                if ($payment['status'] === 'open' && ! isset($settleOn[$id])) {
                    $settleOn[$id] = Carbon::parse($payment['created_at'])->addDays(3)->toDateString();
                }
            }
        }

        if (isset($today['check'])) {
            $label = 'four days after renewal '.($today['check'] + 1);

            // One quota credit per renewal — never two, never none.
            expect($run->count('Tokens', 'period_credit'))->toBe($renewals, $label);
            expect($run->count('SMS', 'subscription_renewal_rollover'))->toBe($renewals, $label);

            // Everything has settled by now.
            expect($run->openOveragePayment())->toBeNull($label);
            expect($run->balance('Tokens'))->toBe($tokensAvailable, $label);
            expect($run->balance('SMS'))->toBe($smsBalance, $label);

            expect($run->paymentRequests)->toBe($charges, $label);
            expect($run->billedUnits())->toBe(array_filter($billed), $label);
            expect($run->overageInvoicedNet())->toBe($billed['Tokens@10'] * 10 + $billed['SMS@15'] * 15, $label);
        }
    }

    expect($renewals)->toBe(12);

    // Nothing was ever forgiven: a `period_reset` deposit is a debt zeroed by a reset.
    $forgiven = array_filter($run->ledger('Tokens'), fn (array $row): bool => $row['reason'] === 'period_reset' && $row['amount'] > 0);
    expect($forgiven)->toBe([]);
});
