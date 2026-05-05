<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('allows creating a 100% Recurring coupon — handled via deferred Mollie startDate', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE100',
        'name' => 'Recurring 100%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    expect($coupon->discount_value)->toBe(100);
});

it('rejects creating a Recurring coupon with discount > 100%', function (): void {
    $service = app(CouponService::class);

    expect(fn () => $service->create([
        'code' => 'OVER100',
        'name' => 'Recurring 101%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 101,
        'max_redemptions_per_billable' => 3,
    ]))->toThrow(\InvalidArgumentException::class);
});

it('rejects creating a 100% FirstPayment coupon at admin time', function (): void {
    $service = app(CouponService::class);

    expect(fn () => $service->create([
        'code' => 'FIRST100',
        'name' => 'FirstPayment 100%',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]))->toThrow(\InvalidArgumentException::class);
});

it('still allows creating a 99% Recurring coupon at admin time', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC99',
        'name' => 'Recurring 99%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 99,
        'max_redemptions_per_billable' => 3,
    ]);
    expect($coupon->code)->toBe('REC99');
});

it('still rejects a fixed-amount FirstPayment coupon that fully covers the order', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'FIX1000',
        'name' => 'Fixed 10€ off',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    // orderAmountNet=1000, fixed=1000 → discount equals order → fully covered.
    // FirstPayment cannot use the deferred-startDate trick (charge is immediate),
    // so full coverage on FirstPayment remains rejected at validate-time.
    expect(fn () => $service->validate('FIX1000', $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]))->toThrow(InvalidCouponException::class);
});

it('allows applying a 100% Recurring coupon — deferred startDate handles the charge', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'FREE100',
        'name' => 'Recurring 100%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    $coupon = $service->validate('FREE100', $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect($coupon->code)->toBe('FREE100');
});

it('accepts a 99% Recurring coupon (not full coverage)', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'REC99',
        'name' => 'Recurring 99%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 99,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    $coupon = $service->validate('REC99', $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect($coupon->code)->toBe('REC99');
});
