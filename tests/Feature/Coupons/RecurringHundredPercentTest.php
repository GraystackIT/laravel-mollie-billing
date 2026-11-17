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

afterEach(fn () => Carbon::setTestNow());

it('marker valid_until = the day before the charge N months on, on the billing day (monthly)', function (): void {
    // Since 17 November 2026 the periods are calendar months on the signup's day — they
    // were `N × 30 + 1` days. Signed up on 31 January: charges 28 Feb, 31 Mar, 30 Apr.
    Carbon::setTestNow(Carbon::parse('2027-01-31 10:00:00', 'UTC'));
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

    // Three periods: 31 Jan, 28 Feb, 31 Mar. The first charge not covered is 30 April.
    expect($marker['valid_until'])->toBe('2027-04-29T10:00:00+00:00')
        ->and($marker['charge_resumes_at'])->toBe('2027-04-30T10:00:00+00:00');
    Carbon::setTestNow();
});

it('marker valid_until = the day before the charge N years on, on the billing day (yearly)', function (): void {
    // Signed up on 29 February 2028; one year is 28 February 2029 — not 366 days.
    Carbon::setTestNow(Carbon::parse('2028-02-29 10:00:00', 'UTC'));
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

    expect($marker['valid_until'])->toBe('2029-02-27T10:00:00+00:00')
        ->and($marker['charge_resumes_at'])->toBe('2029-02-28T10:00:00+00:00');
    Carbon::setTestNow();
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
