<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 14,
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

it('extends trial_ends_at on a Mollie subscription and patches the Mollie startDate to match', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->trialExtensionCoupon('TRIAL14', 14);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $trialEnd = BillingTime::nowUtc()->addDays(3);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    $service->redeem($coupon, $billable->fresh(), ['planCode' => 'pro']);

    $billable->refresh();

    $expected = $trialEnd->copy()->addDays(14);
    expect($billable->trial_ends_at->diffInSeconds($expected, false))->toBeBetween(-2, 2);

    $setCalls = array_values(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    ));
    expect($setCalls)->toHaveCount(1);

    $target = \Carbon\Carbon::parse((string) $setCalls[0][3]['target']);
    expect($target->diffInSeconds($expected, false))->toBeBetween(-2, 2);

    expect($billable->subscription_meta['next_charge_date_override'] ?? null)->not->toBeNull();
});

it('does not call the Mollie patcher when the billable runs on a Local subscription', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->trialExtensionCoupon('TRIAL7', 7);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(3),
    ])->save();

    $service->redeem($coupon, $billable->fresh(), ['planCode' => 'pro']);

    $setCalls = array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    );
    expect($setCalls)->toBeEmpty();
});

it('aligns the Mollie startDate with the new trial end even when the previous trial already expired', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->trialExtensionCoupon('REVIVE14', 14);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(20),
        'trial_ends_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    $service->redeem($coupon, $billable->fresh(), ['planCode' => 'pro']);

    $billable->refresh();

    $expected = BillingTime::nowUtc()->addDays(14);
    expect($billable->trial_ends_at->diffInSeconds($expected, false))->toBeBetween(-2, 2);

    $setCalls = array_values(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    ));
    expect($setCalls)->toHaveCount(1);

    $target = \Carbon\Carbon::parse((string) $setCalls[0][3]['target']);
    expect($target->diffInSeconds($expected, false))->toBeBetween(-2, 2);
});
