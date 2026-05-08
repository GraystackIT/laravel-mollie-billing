<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
});

it('requires grant_duration_days for period_extension coupons', function (): void {
    $service = app(CouponService::class);

    $service->create([
        'code' => 'plus30',
        'name' => 'Plus 30 days',
        'type' => CouponType::PeriodExtension,
    ]);
})->throws(\InvalidArgumentException::class);

it('extends a local subscription end date by N days when redeemed', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'plus30',
        'name' => 'Plus 30 days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 30,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $endsAt = BillingTime::nowUtc()->addDays(10);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(20),
        'subscription_ends_at' => $endsAt,
    ])->save();

    $service->redeem($coupon, $billable->fresh(), []);

    $billable->refresh();
    expect($billable->subscription_ends_at->diffInDays($endsAt->copy()->addDays(30), false))
        ->toBeBetween(-1, 1);
});

it('pushes Mollie next charge date by N days when redeemed for a Mollie subscription', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'plus7',
        'name' => 'Plus 7 days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 7,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    $service->redeem($coupon, $billable->fresh(), []);

    $pushCalls = array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'push_next_charge_date',
    );
    expect($pushCalls)->toHaveCount(1);

    $first = array_values($pushCalls)[0];
    expect($first[3]['days'] ?? null)->toBe(7);
});

it('rejects period_extension when next charge is within 24 hours', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'plus7',
        'name' => 'Plus 7 days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 7,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        // period started ~30 days ago for monthly interval → next charge is in just hours.
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(30)->addHours(2),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    expect(fn () => $service->validate('PLUS7', $billable->fresh(), []))
        ->toThrow(InvalidCouponException::class);
});

it('rejects period_extension when there is no active subscription', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'plus7',
        'name' => 'Plus 7 days',
        'type' => CouponType::PeriodExtension,
        'grant_duration_days' => 7,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    expect(fn () => $service->validate('PLUS7', $billable->fresh(), []))
        ->toThrow(InvalidCouponException::class);
});
