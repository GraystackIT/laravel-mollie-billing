<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * Coupon fields whose effect had no test of its own: set, empty, and at the boundary.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => ['tokens' => 10]]],
    ]);
});

function couponFieldReason(callable $attempt): ?string
{
    try {
        $attempt();
    } catch (InvalidCouponException $e) {
        return $e->reason();
    }

    return null;
}

it('refuses an order below the minimum and takes one at it', function (CouponType $type): void {
    $coupons = app(CouponService::class);
    $coupons->create([
        'code' => 'MIN50', 'type' => $type,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
        'minimum_order_amount_net' => 5000,
    ] + ($type === CouponType::Recurring ? ['discount_periods' => 2] : []));

    $at = fn (int $net): ?string => couponFieldReason(fn () => $coupons->validateWithoutBillable('MIN50', [
        'planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => $net,
    ]));

    expect($at(4999))->toBe('min_order_not_met')
        ->and($at(5000))->toBeNull()
        ->and($at(5001))->toBeNull();

    // Without a minimum any order will do.
    $coupons->create([
        'code' => 'NOMIN', 'type' => $type, 'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
    ] + ($type === CouponType::Recurring ? ['discount_periods' => 2] : []));

    expect(couponFieldReason(fn () => $coupons->validateWithoutBillable('NOMIN', ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 1])))->toBeNull();
})->with([
    'single payment' => CouponType::SinglePayment,
    'recurring' => CouponType::Recurring,
]);

it('refuses a usage-restricted coupon everywhere, whatever its type', function (array $attributes): void {
    // No charge in the package carries a usage dimension, so nothing can satisfy the
    // restriction — and a restriction nothing satisfies must refuse, not apply to all.
    $coupons = app(CouponService::class);
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Usage', 'email' => uniqid('us-').'@x.test']);

    $coupons->create(['code' => 'USAGEONLY', 'applicable_usages' => ['tokens']] + $attributes);

    foreach ([
        [],
        ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000],
        ['productCodes' => ['token-pack'], 'orderAmountNet' => 1000],
    ] as $context) {
        expect(couponFieldReason(fn () => $coupons->validate('USAGEONLY', $billable, $context)))->toBe('usage_not_applicable');
    }

    // The one way through: a caller that names the usage it is charging for.
    expect(couponFieldReason(fn () => $coupons->validateWithoutBillable('USAGEONLY', ['usageTypes' => ['tokens'], 'planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000])))
        ->toBeNull();
})->with([
    'credits' => [['type' => CouponType::Credits, 'credits_payload' => ['tokens' => 5]]],
    'single payment' => [['type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 10]],
    'recurring' => [['type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 2]],
]);
