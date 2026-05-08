<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\CouponNotStackableException;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
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

it('SubscriptionUpdateRequest::from accepts coupon_codes (array) and falls back to coupon_code (single)', function (): void {
    $multi = SubscriptionUpdateRequest::from(['coupon_codes' => ['ABC', 'def']]);
    expect($multi->couponCodes)->toBe(['ABC', 'DEF']);

    $single = SubscriptionUpdateRequest::from(['coupon_code' => 'XYZ']);
    expect($single->couponCodes)->toBe(['XYZ']);

    $empty = SubscriptionUpdateRequest::from([]);
    expect($empty->couponCodes)->toBe([]);
});

it('redeems multiple stackable coupons in one update and writes one redemption per code', function (): void {
    // Recurring coupons (single_payment is no longer accepted on plan change /
    // seat sync / addon enable). Stacking semantics are coupon-type-agnostic.
    $service = app(CouponService::class);
    $a = $service->create([
        'code' => 'STACK10',
        'name' => 'Stackable 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'stackable' => true,
        'max_redemptions_per_billable' => 3,
    ]);
    $b = $service->create([
        'code' => 'STACK5',
        'name' => 'Stackable 5%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 5,
        'stackable' => true,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Stack', 'email' => 'stack@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ])->save();

    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'coupon_codes' => ['STACK10', 'STACK5'],
    ]);

    expect(CouponRedemption::query()->where('coupon_id', $a->id)->count())->toBe(1);
    expect(CouponRedemption::query()->where('coupon_id', $b->id)->count())->toBe(1);
});

it('rejects a non-stackable coupon when another coupon is already in the apply set', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'STACKABLE',
        'name' => 'Stackable',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'stackable' => true,
        'max_redemptions_per_billable' => 3,
    ]);
    $service->create([
        'code' => 'EXCLUSIVE',
        'name' => 'Exclusive',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'stackable' => false,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Stack', 'email' => 'stack@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ])->save();

    expect(fn () => app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'coupon_codes' => ['STACKABLE', 'EXCLUSIVE'],
    ]))->toThrow(CouponNotStackableException::class);
});
