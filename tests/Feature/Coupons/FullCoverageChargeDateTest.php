<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest as MollieUpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A fully covering (100 %) recurring coupon defers Mollie's charge past its lifetime.
 * That deferred date used to exist at Mollie only, so everything that later touched the
 * charge date got it wrong. See Support\ChargeDeferral and docs/decisions.md.
 *
 * Both leading tests were written against the unfixed code and seen to fail there.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

function fullyCoveredBillable(bool $storeDeferral = true): array
{
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100',
        'name' => 'Plan free for 3 months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Granted', 'email' => 'g@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(2),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test', 'seat_count' => 1],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    // Marker: 100 % of the 10.00 plan, valid for 91 days. Mollie's start date is
    // therefore deferred to valid_until + 1 day (see fullCoverageStartDate()).
    $service->applyRecurringMarker($coupon, $billable->fresh(), 1000, 'monthly');

    if ($storeDeferral) {
        $billable = $billable->fresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $meta[ChargeDeferral::META_KEY] = Carbon::parse((string) $meta['active_recurring_coupon']['valid_until'])
            ->addDay()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    return [$billable->fresh(), $coupon];
}

function patchedStartDate(MollieUpdateSubscriptionRequest $request): mixed
{
    $p = (new \ReflectionClass($request))->getProperty('startDate');
    $p->setAccessible(true);

    return $p->getValue($request);
}

function patchedAmount(MollieUpdateSubscriptionRequest $request): mixed
{
    $p = (new \ReflectionClass($request))->getProperty('amount');
    $p->setAccessible(true);

    return $p->getValue($request);
}

it('charges a seat added under a 100% coupon from the next regular billing date', function (): void {
    [$billable] = fullyCoveredBillable();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_test'];
    });

    // One extra seat (5.00) is added. The coupon is locked to base_amount_net = 1000,
    // so the discount no longer covers the charge: 1500 - 1000 = 500 net is owed.
    $discount = app(CouponService::class)->computeMarkerDiscount($billable, 1500);
    expect($discount)->toBe(1000);

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: $discount,
    );

    expect($captured)->toBeInstanceOf(MollieUpdateSubscriptionRequest::class);
    // 5.00 net + 20 % AT VAT.
    expect(patchedAmount($captured)->value)->toBe('6.00');

    // Mollie still holds the start date deferred past the coupon (≈ now + 92 days). A
    // PATCH without a startDate leaves it there, so the seat would be free for three
    // months. The PATCH must pull the charge back to the regular cycle.
    $startDate = patchedStartDate($captured);
    expect($startDate)->not->toBeNull('PATCH carries no startDate — Mollie keeps the deferred one and charges nothing until the coupon ends');

    $regularNext = $billable->nextBillingDate();
    expect(str_contains((string) $startDate, $regularNext->format('Y-m-d')))->toBeTrue();
});

it('pulls the charge back for a subscription deferred before the date was stored', function (): void {
    // Legacy shape: the marker is all there is to say the charge is deferred.
    [$billable] = fullyCoveredBillable(storeDeferral: false);

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_test'];
    });

    // The seat is already written locally when the PATCH runs.
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable->fresh(),
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: 1000,
    );

    expect(patchedStartDate($captured))->not->toBeNull();
});

it('forgets the deferral once the charge is back on the regular cycle', function (): void {
    [$billable] = fullyCoveredBillable();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_test']);

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: 1000,
    );

    expect($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(ChargeDeferral::META_KEY);
});

it('rolls a stale anchor forward instead of sending Mollie a start date in the past', function (): void {
    [$billable] = fullyCoveredBillable();
    $billable->forceFill(['subscription_period_starts_at' => BillingTime::nowUtc()->subDays(70)])->save();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_test'];
    });

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable->fresh(),
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: 1000,
    );

    $start = Carbon::parse((string) patchedStartDate($captured));
    expect($start->isFuture())->toBeTrue();
});

it('keeps the deferral, and records it, while the coupon still covers everything', function (): void {
    [$billable] = fullyCoveredBillable(storeDeferral: false);
    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'];
    $expected = Carbon::parse((string) $marker['valid_until'])->addDay();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_test']);

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 0,
        couponDiscountNet: 1000,
    );

    $stored = Carbon::parse((string) $billable->fresh()->getBillingSubscriptionMeta()[ChargeDeferral::META_KEY]);
    expect($stored->toDateString())->toBe($expected->toDateString());
});

it('never moves the next charge of a fully covered subscription earlier with a period extension', function (): void {
    [$billable] = fullyCoveredBillable();
    $service = app(CouponService::class);

    $deferredUntil = ChargeDeferral::until($billable);
    $periodEnd = $billable->nextBillingDate();

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'sub_test'];
    });

    $extension = $service->create([
        'code' => 'PLUS14',
        'name' => 'Plus 14 days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 14,
    ]);

    $service->validate('PLUS14', $billable, []);
    $service->redeem($extension, $billable, []);

    $billable = $billable->fresh();

    // Mollie holds the FULL plan price (full coverage relies on the deferral alone), so
    // the extension must land AFTER the deferred date: 14 days appended to it.
    $chargeAt = ChargeDeferral::until($billable);
    expect($chargeAt->toDateString())->toBe($deferredUntil->copy()->addDays(14)->toDateString());

    // The period itself keeps its regular cycle — only the charge moved.
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override');
    expect($billable->nextBillingDate()->toDateString())->toBe($periodEnd->toDateString());

    // And that is the date Mollie was sent, once the redemption had committed.
    $dates = array_values(array_filter(array_map(
        fn ($r) => $r instanceof MollieUpdateSubscriptionRequest ? patchedStartDate($r) : null,
        $captured,
    )));
    if ($dates !== []) {
        expect(str_contains((string) end($dates), $chargeAt->format('Y-m-d')))->toBeTrue();
    }
});

it('does not let a trial extension pull the charge in front of a fully covering coupon', function (): void {
    [$billable] = fullyCoveredBillable();
    $deferredUntil = ChargeDeferral::until($billable);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_test']);

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(5),
    ])->save();

    $billable->fresh()->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(12));

    $chargeAt = ChargeDeferral::until($billable->fresh());
    expect($chargeAt->toDateString())->toBe($deferredUntil->toDateString());
});
