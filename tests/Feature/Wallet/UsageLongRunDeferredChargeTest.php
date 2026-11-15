<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;

/**
 * Subscriptions Mollie is not charging: a discount covers the whole recurring amount, so
 * the charge is deferred and no renewal webhook arrives. The nightly pass turns their
 * period over (case A0) — and at the end of the deferral the webhook takes over again.
 * That hand-over is where a period can be credited twice.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

function usageLongRunDeferredBillable(UsageLongRunHarness $run, Carbon $start): void
{
    UsageLongRunHarness::definePlan();
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);
}

it('credits each period of a 100 % coupon once, and the first paid period once', function (int $runsPerNight): void {
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-10T09:00:00Z');
    usageLongRunDeferredBillable($run, $start);

    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FREE3M',
        'name' => 'Free for three periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    $coupons->applyRecurringMarker($coupon, $run->fresh(), 1000, 'monthly');

    // What CreateSubscription stores for a fully covered subscription: the charge is
    // deferred to the day after the marker's own end (now + 3 × 30 + 1 days).
    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();
    $deferredUntil = Carbon::parse($meta['active_recurring_coupon']['valid_until'])->addDay();
    $meta[ChargeDeferral::META_KEY] = $deferredUntil->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // 2026-04-12: two days after the third period boundary (10 April).
    expect($deferredUntil->toDateString())->toBe('2026-04-12');

    $firstCharge = $deferredUntil->copy()->setTime(9, 0);
    $renewals = [
        $firstCharge->toDateString() => $firstCharge,
        '2026-05-12' => Carbon::parse('2026-05-12T09:00:00Z'),
        '2026-06-12' => Carbon::parse('2026-06-12T09:00:00Z'),
    ];
    $usage = [
        // Second covered period: 37 Tokens over the quota.
        '2026-02-24' => ['Tokens', 137],
        // The day between the last covered boundary and the first charge.
        '2026-04-11' => ['Tokens', 40],
        '2026-05-01' => ['SMS', 30],
    ];

    $anchors = [];

    for ($day = $start->copy()->addDay()->startOfDay(); $day->lessThan(Carbon::parse('2026-06-20')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));

        for ($i = 0; $i < $runsPerNight; $i++) {
            $run->night();
        }
        $run->settleOpenOverage();

        if (isset($usage[$day->toDateString()])) {
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use(...$usage[$day->toDateString()]);
        }

        if (isset($renewals[$day->toDateString()])) {
            $run->travelTo($renewals[$day->toDateString()]->copy()->addMinutes(2));
            $run->renew($renewals[$day->toDateString()]);
            $run->settleOpenOverage();
        }

        $anchor = $run->fresh()->subscription_period_starts_at->toIso8601String();
        if (end($anchors) !== $anchor) {
            $anchors[] = $anchor;
        }
    }

    // The cycle keeps its day and its time through the covered periods, then follows the
    // money — it never slides to midnight.
    expect($anchors)->toBe([
        '2026-01-10T09:00:00+00:00',
        '2026-02-10T09:00:00+00:00',
        '2026-03-10T09:00:00+00:00',
        '2026-04-10T09:00:00+00:00',
        '2026-04-12T09:00:00+00:00',
        '2026-05-12T09:00:00+00:00',
        '2026-06-12T09:00:00+00:00',
    ]);

    // Five periods began after the first: 10 Feb, 10 Mar, then the paid ones on 12 Apr,
    // 12 May and 12 Jun. The two days from 10 to 12 April belong to the third covered
    // period — they are not a period of their own and get no quota of their own.
    expect($run->count('Tokens', 'period_credit'))->toBe(5)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(5 * 50)
        ->and($run->balance('SMS'))->toBe(50 + 5 * 50 - 30)
        ->and($run->balance('Tokens'))->toBe(100);

    // The covered period's overage was collected, once, although no renewal came.
    expect($run->billedUnits())->toBe(['Tokens@10' => 37])
        ->and($run->paymentRequests)->toBe(1)
        ->and($run->invoiceCount('subscription'))->toBe(3);
})->with(['every night once' => 1, 'every night twice' => 2]);

it('turns the period of a waived plan over until the charge returns, without a double credit', function (): void {
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-10T09:00:00Z');
    usageLongRunDeferredBillable($run, $start);

    // Five days into the first period the operator makes the plan free for three periods.
    $run->travelTo($start->copy()->addDays(5));
    app(AdminExtensionService::class)->freePlanPeriods($run->fresh(), 3);

    $deferredUntil = ChargeDeferral::until($run->fresh());

    // Three charges are skipped — 10 Feb, 10 Mar, 10 Apr — and the next one is 10 May.
    expect($deferredUntil?->toIso8601String())->toBe('2026-05-10T09:00:00+00:00');

    $renewals = [
        '2026-05-10' => Carbon::parse('2026-05-10T09:00:00Z'),
        '2026-06-10' => Carbon::parse('2026-06-10T09:00:00Z'),
    ];

    $anchors = [];

    for ($day = $start->copy()->addDays(6)->startOfDay(); $day->lessThan(Carbon::parse('2026-06-20')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->settleOpenOverage();

        // 20 Tokens over the quota in every period, waived or not.
        if ($day->day === 20) {
            $run->travelTo($day->copy()->setTime(12, 0));
            $run->use('Tokens', 120);
        }

        if (isset($renewals[$day->toDateString()])) {
            $run->travelTo($renewals[$day->toDateString()]->copy()->addMinutes(2));
            $run->renew($renewals[$day->toDateString()]);
            $run->settleOpenOverage();
        }

        $anchor = $run->fresh()->subscription_period_starts_at->toIso8601String();
        if (end($anchors) !== $anchor) {
            $anchors[] = $anchor;
        }
    }

    expect($anchors)->toBe([
        '2026-01-10T09:00:00+00:00',
        '2026-02-10T09:00:00+00:00',
        '2026-03-10T09:00:00+00:00',
        '2026-04-10T09:00:00+00:00',
        '2026-05-10T09:00:00+00:00',
        '2026-06-10T09:00:00+00:00',
    ]);

    // One credit per boundary: three from the nightly pass, two from the webhook.
    expect($run->count('Tokens', 'period_credit'))->toBe(5)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(5 * 50)
        ->and($run->balance('Tokens'))->toBe(100)
        // Five periods ended with 20 Tokens of overage; each was billed once.
        ->and($run->billedUnits())->toBe(['Tokens@10' => 100])
        ->and($run->paymentRequests)->toBe(5);
});

it('ends a gift that was given to end: cancelled by the nightly pass, expired after, no further quota', function (): void {
    $run = UsageLongRunHarness::boot();
    $start = Carbon::parse('2026-01-10T09:00:00Z');
    usageLongRunDeferredBillable($run, $start);

    $run->travelTo($start->copy()->addDays(5));
    app(AdminExtensionService::class)->freePlanPeriods($run->fresh(), 2);
    app(PlanWaiver::class)->setOnExpiry($run->fresh(), PlanWaiver::ON_EXPIRY_END);

    expect(ChargeDeferral::until($run->fresh())?->toDateString())->toBe('2026-04-10');

    $statuses = [];

    for ($day = $start->copy()->addDays(6)->startOfDay(); $day->lessThan(Carbon::parse('2026-07-01')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->settleOpenOverage();

        $status = $run->fresh()->subscription_status->value;
        if (($statuses[array_key_last($statuses) ?? ''] ?? null) !== $status) {
            $statuses[$day->toDateString()] = $status;
        }
    }

    $billable = $run->fresh();

    // The gift covers the charges of 10 Feb and 10 Mar. The pass that starts the last
    // covered period (10 Mar – 10 Apr) cancels to its end; the next pass after that end
    // expires. Mollie never charges, and no fourth period is credited.
    expect($statuses)->toBe([
        '2026-01-16' => 'active',
        '2026-03-09' => 'cancelled',
        '2026-04-11' => 'expired',
    ]);

    expect($billable->subscription_plan_code)->toBeNull()
        ->and($run->count('Tokens', 'period_credit'))->toBe(2)
        ->and($run->units('SMS', 'subscription_renewal_rollover'))->toBe(2 * 50)
        ->and(in_array(CancelSubscriptionRequest::class, $run->requests, true))->toBeTrue()
        ->and($run->paymentRequests)->toBe(0);
});
