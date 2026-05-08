<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('marker valid_until = now + max_redemptions × intervalDays + 1d (monthly)', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100M',
        'name' => 'Free 3 months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill(['subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly])->save();

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    // 3 × 30 + 1 = 91 days lifetime.
    $expected = BillingTime::nowUtc()->copy()->addDays(91);
    expect(Carbon::parse($marker['valid_until'])->diffInSeconds($expected, true))->toBeLessThan(5);
});

it('marker valid_until = now + max_redemptions × 365 + 1d (yearly)', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100Y',
        'name' => 'Free 1 year',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 1,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill(['subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Yearly])->save();

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'yearly',
        'orderAmountNet' => 10000,
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    // 1 × 365 + 1 = 366 days lifetime.
    $expected = BillingTime::nowUtc()->copy()->addDays(366);
    expect(Carbon::parse($marker['valid_until'])->diffInSeconds($expected, true))->toBeLessThan(5);
});

it('marker valid_until is the earlier of coupon.valid_until and duration', function (): void {
    $service = app(CouponService::class);
    // Coupon valid_until = 60 days; duration would be 91 days → valid_until wins.
    $coupon = $service->create([
        'code' => 'FREE100MIX',
        'name' => 'Free with hard end date',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
        'valid_until' => Carbon::now()->addDays(60)->toIso8601String(),
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill(['subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly])->save();

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    $expected = $coupon->valid_until;
    expect(Carbon::parse($marker['valid_until'])->diffInSeconds($expected, true))->toBeLessThan(5);
});

it('computeMarkerDiscount returns the full base amount for a 100% recurring coupon', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100',
        'name' => 'Free 3 months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect($service->computeMarkerDiscount($billable->fresh(), 1000))->toBe(1000);
});

it('marker expires once valid_until is in the past — discount drops to 0', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100',
        'name' => 'Free 3 months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill(['subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly])->save();

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    // Within the discount window — full coverage.
    expect($service->markerExpired($billable->fresh()))->toBeFalse()
        ->and($service->computeMarkerDiscount($billable->fresh(), 1000))->toBe(1000);

    // Past it — discount gone.
    Carbon::setTestNow(Carbon::now()->addDays(92));
    expect($service->markerExpired($billable->fresh()))->toBeTrue()
        ->and($service->computeMarkerDiscount($billable->fresh(), 1000))->toBe(0);

    Carbon::setTestNow();
});
