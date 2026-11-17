<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;

/**
 * The stretches of a subscription's life where the period is not simply "one interval
 * after the last renewal": an extension, a trial, a yearly cycle, a dunning episode.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

it('credits one quota for a period an operator extended, and loses none of its overage', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    // Renewals on Mollie's cycle: 10 Feb, 10 Mar — then the March period is extended by
    // ten days on its twelfth day, so the next charges are 20 Apr and 20 May.
    $renewals = ['2026-02-10', '2026-03-10', '2026-04-20', '2026-05-20'];
    $usage = [
        '2026-03-25' => 130,  // inside the original period
        '2026-04-15' => 15,   // inside the ten extra days
        '2026-05-05' => 100,
    ];

    $credits = [];

    for ($day = $start->copy()->addDay()->startOfDay(); $day->lessThan(Carbon::parse('2026-05-25')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->settleOpenOverage();

        if ($day->toDateString() === '2026-03-22') {
            $run->travelTo($day->copy()->setTime(10, 0));
            app(AdminExtensionService::class)->extendByDays($run->fresh(), 10);

            expect($run->fresh()->nextBillingDate()->toIso8601String())->toBe('2026-04-20T09:00:00+00:00');
        }

        if (isset($usage[$day->toDateString()])) {
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use('Tokens', $usage[$day->toDateString()]);
        }

        if (in_array($day->toDateString(), $renewals, true)) {
            $run->travelTo($day->copy()->setTime(9, 2));
            $run->renew($day->copy()->setTime(9, 0));
            $run->settleOpenOverage();
        }

        if ($run->count('Tokens', 'period_credit') !== count($credits)) {
            $credits[] = $day->toDateString();
        }
    }

    // One credit per charge. In particular: none on 10 April, where the period would have
    // ended, and exactly one on 20 April, where it did.
    expect($credits)->toBe($renewals);
    expect($run->units('SMS', 'subscription_renewal_rollover'))->toBe(4 * 50);

    // The extended period ran 45 Tokens over (130 + 15 against 100) and was billed for
    // exactly that, once — not on the old boundary, not twice.
    expect($run->billedUnits())->toBe(['Tokens@10' => 45])
        ->and($run->paymentRequests)->toBe(1)
        ->and($run->balance('Tokens'))->toBe(100);

    // The extension is spent: the cycle now runs from the 20th.
    $billable = $run->fresh();
    expect($billable->subscription_period_starts_at->toIso8601String())->toBe('2026-05-20T09:00:00+00:00')
        ->and($billable->nextBillingDate()->toIso8601String())->toBe('2026-06-20T09:00:00+00:00')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override');
});

it('runs a trial into its first charge and on through three paid periods', function (): void {
    UsageLongRunHarness::definePlan('pro', ['trial_days' => 14]);
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $trialEnd = $start->copy()->addDays(14);

    $run->travelTo($start);
    $run->createBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_period_starts_at' => $start,
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_run',
            // ceil(100 × 14/30) = 47, ceil(50 × 14/30) = 24
            'trial_quota' => TrialQuota::marker('pro', 'monthly', 14, ['Tokens' => 47, 'SMS' => 24]),
        ],
    ]);
    $run->credit('Tokens', 47, 'subscription_trial_start');
    $run->credit('SMS', 24, 'subscription_trial_start');

    // The first charge is a SEPA debit: collected at the trial end, `paid` three days on.
    $firstCharge = $trialEnd->copy()->addDays(3);
    $renewals = [
        $firstCharge->toDateString() => $firstCharge,
        '2026-02-27' => Carbon::parse('2026-02-27T09:00:00Z'),
        '2026-03-27' => Carbon::parse('2026-03-27T09:00:00Z'),
        '2026-04-27' => Carbon::parse('2026-04-27T09:00:00Z'),
    ];
    $usage = [
        '2026-01-18' => ['Tokens', 97],   // trial: 50 over the grant of 47
        '2026-02-10' => ['Tokens', 100],
        '2026-03-10' => ['Tokens', 112],
        '2026-04-10' => ['SMS', 10],
    ];

    for ($day = $start->copy()->addDay()->startOfDay(); $day->lessThan(Carbon::parse('2026-05-02')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night(trialJob: true);
        $run->settleOpenOverage();

        // Between the trial end and the settled charge the trial is simply waiting.
        if ($day->betweenIncluded($trialEnd, $firstCharge->copy()->subDay())) {
            expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial, $day->toDateString());
        }

        if (isset($usage[$day->toDateString()])) {
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use(...$usage[$day->toDateString()]);
        }

        if (isset($renewals[$day->toDateString()])) {
            $paidAt = $renewals[$day->toDateString()];
            $run->travelTo($paidAt->copy()->addMinutes(2));
            $run->renew($paidAt);
            $run->settleOpenOverage();

            expect($run->fresh()->subscription_period_starts_at->toIso8601String())->toBe($paidAt->toIso8601String());
        }
    }

    $billable = $run->fresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('trial_quota');

    // Four charges, four quota credits — the first of them the conversion's.
    expect($run->count('Tokens', 'period_credit'))->toBe(4)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(4 * 50)
        ->and($run->balance('SMS'))->toBe(24 + 4 * 50 - 10)
        ->and($run->balance('Tokens'))->toBe(100);

    // The trial's 50 units and March's 12 — each billed once, at the plan's price.
    expect($run->billedUnits())->toBe(['Tokens@10' => 62])
        ->and($run->paymentRequests)->toBe(2);
});

it('renews a yearly subscription once a year for three years', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-03-15T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable([
        'subscription_period_starts_at' => $start,
        'subscription_interval' => SubscriptionInterval::Yearly,
    ]);
    $run->credit('Tokens', 1200);
    $run->credit('SMS', 600);

    // Tokens used per year, half in June and half in January.
    $perYear = [1000, 1337, 1200];
    $credits = [];

    for ($day = $start->copy()->addDay()->startOfDay(); $day->lessThan(Carbon::parse('2029-03-20')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->settleOpenOverage();

        $year = (int) floor($start->diffInDays($day) / 365.25);
        $inYear = $perYear[min($year, 2)];

        if (($day->month === 6 && $day->day === 1) || ($day->month === 1 && $day->day === 10)) {
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use('Tokens', $day->month === 6 ? intdiv($inYear, 2) : $inYear - intdiv($inYear, 2));
        }

        if ($day->month === 3 && $day->day === 15) {
            $run->travelTo($day->copy()->setTime(9, 2));
            $run->renew($day->copy()->setTime(9, 0), '119.00');
            $run->settleOpenOverage();
        }

        if ($run->count('Tokens', 'period_credit') !== count($credits)) {
            $credits[] = $day->toDateString();
        }
    }

    expect($credits)->toBe(['2027-03-15', '2028-03-15', '2029-03-15']);
    expect($run->units('SMS', 'subscription_renewal_rollover'))->toBe(3 * 600)
        ->and($run->balance('Tokens'))->toBe(1200)
        // Only the second year ran over: 137 units, billed once in three years of nights.
        ->and($run->billedUnits())->toBe(['Tokens@10' => 137])
        ->and($run->paymentRequests)->toBe(1)
        ->and($run->fresh()->nextBillingDate()->toIso8601String())->toBe('2030-03-15T09:00:00+00:00');
});

/**
 * A subscription whose overage payment, charged the night before the February renewal,
 * fails three times in a row.
 *
 * @return array{0: UsageLongRunHarness, 1: Carbon}
 */
function usageLongRunIntoPastDue(): array
{
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-24T12:00:00Z');
    $run->use('Tokens', 137);

    for ($day = Carbon::parse('2026-01-25'); $day->lessThanOrEqualTo(Carbon::parse('2026-02-09')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
    }

    // The sweep charged on the eve of the renewal; the card declines, and declines the
    // two retries as well.
    expect($run->paymentRequests)->toBe(1);

    for ($attempt = 0; $attempt < 6 && ($id = $run->openOveragePayment()) !== null; $attempt++) {
        $run->travelTo(Carbon::now('UTC')->addMinutes(10));
        $run->resolveOverage($id, 'failed');
    }

    // The cycle gave up. The debt stands for the renewals, and until it is settled the
    // subscription is past due — for the debt, without the clock of a failed renewal.
    expect($run->paymentRequests)->toBe(3)
        ->and($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($run->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        ->and($run->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)->toBe('failed');

    return [$run, $start];
}

it('cancels and expires a subscription whose renewal fails, and credits it nothing more', function (): void {
    [$run] = usageLongRunIntoPastDue();

    // The renewal fails too — and that, not the overage, is what starts the clock that
    // ends in a cancellation.
    $run->travelTo('2026-02-10T09:02:00Z');
    $run->renew('2026-02-10T09:00:00Z', status: 'failed');

    $statuses = [];
    $requestsAtCancel = null;

    for ($day = Carbon::parse('2026-02-11'); $day->lessThan(Carbon::parse('2026-05-01')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night(sweepJob: true);

        $status = $run->fresh()->subscription_status->value;
        if (end($statuses) !== $status) {
            $statuses[$day->toDateString()] = $status;

            if ($status === 'cancelled') {
                $requestsAtCancel = $run->paymentRequests;
            }
        }

        // Whatever is attempted against this payment method fails.
        for ($attempt = 0; $attempt < 6 && ($id = $run->openOveragePayment()) !== null; $attempt++) {
            $run->resolveOverage($id, 'failed');
        }
    }

    // 30 days of dunning from the failed renewal (10 Feb), then cancelled at once and
    // expired the night after.
    expect($statuses)->toBe([
        '2026-02-11' => 'past_due',
        '2026-03-13' => 'cancelled',
        '2026-03-14' => 'expired',
    ]);

    $billable = $run->fresh();

    expect($billable->subscription_plan_code)->toBeNull()
        ->and(in_array(CancelSubscriptionRequest::class, $run->requests, true))->toBeTrue()
        // No renewal ever settled: not one quota credit, in two and a half months of nights.
        ->and($run->count('Tokens', 'period_credit'))->toBe(0)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(0)
        // Nothing was collected and nothing invoiced.
        ->and($run->overageInvoicedNet())->toBe(0);

    // The immediate cancellation makes one more attempt at the standing debt — its last
    // chance, there is no renewal coming — and that cycle fails the same way. Six requests
    // in all, none of them after the subscription expired.
    expect($requestsAtCancel)->toBe(4)
        ->and($run->paymentRequests)->toBe(6);
});

it('bills a cancelled subscription its last period\'s overage once, then expires it', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-20T12:00:00Z');
    $run->use('Tokens', 137);
    app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class)->handle($run->fresh(), immediately: false);

    expect($run->fresh()->subscription_ends_at->toIso8601String())->toBe('2026-02-10T09:00:00+00:00');

    $statuses = [];

    for ($day = Carbon::parse('2026-01-21'); $day->lessThan(Carbon::parse('2026-04-01')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->night();
        $run->settleOpenOverage();

        $status = $run->fresh()->subscription_status->value;
        if (end($statuses) !== $status) {
            $statuses[$day->toDateString()] = $status;
        }
    }

    expect($statuses)->toBe(['2026-01-21' => 'cancelled', '2026-02-11' => 'expired'])
        // Charged the night before it ended — once, although the pass ran twice a night.
        ->and($run->billedUnits())->toBe(['Tokens@10' => 37])
        ->and($run->paymentRequests)->toBe(1)
        ->and($run->overageInvoicedNet())->toBe(370)
        // And no quota for a period that never began.
        ->and($run->count('Tokens', 'period_credit'))->toBe(0)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(0);
});

it('carries on as if nothing happened when a cancellation is taken back before the period ends', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $renewals = ['2026-02-10', '2026-03-10', '2026-04-10'];

    for ($day = $start->copy()->addDay()->startOfDay(); $day->lessThan(Carbon::parse('2026-04-15')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->settleOpenOverage();

        $run->travelTo($day->copy()->setTime(12, 0));

        if ($day->toDateString() === '2026-01-20') {
            $run->use('Tokens', 137);
            app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class)->handle($run->fresh(), immediately: false);
        }

        if ($day->toDateString() === '2026-02-02') {
            app(\GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription::class)->handle($run->fresh());

            // The paid period keeps its boundary: the next charge is still 10 February.
            expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
                ->and($run->fresh()->nextBillingDate()->toIso8601String())->toBe('2026-02-10T09:00:00+00:00')
                // Resuming is not a new period: no quota is handed out for it.
                ->and($run->balance('Tokens'))->toBe(-37);
        }

        if ($day->toDateString() === '2026-03-20') {
            $run->use('Tokens', 110);
        }

        if (in_array($day->toDateString(), $renewals, true)) {
            $run->travelTo($day->copy()->setTime(9, 2));
            $run->renew($day->copy()->setTime(9, 0));
            $run->settleOpenOverage();
        }
    }

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($run->count('Tokens', 'period_credit'))->toBe(3)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(3 * 50)
        ->and($run->billedUnits())->toBe(['Tokens@10' => 47])
        ->and($run->paymentRequests)->toBe(2)
        ->and($run->balance('Tokens'))->toBe(100)
        ->and($run->fresh()->subscription_period_starts_at->toIso8601String())->toBe('2026-04-10T09:00:00+00:00');
});
