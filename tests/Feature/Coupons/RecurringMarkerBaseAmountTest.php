<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
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
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
});

it('persists base_amount_net in the recurring marker on apply', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000, // plan only at apply-time
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['base_amount_net'])->toBe(1000);
});

it('caps the marker discount to base_amount_net so later seats/addons are NOT discounted', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    // Apply against a 1000-cent plan (no seats, no addons).
    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    // Later, the recurring net grows to 4000 (5 extra seats × 500 + plan 1000 = 3500;
    // we test the cap with any larger value, e.g. 4000).
    $discount = $service->computeMarkerDiscount($billable->fresh(), 4000);

    // Discount basis must be capped at 1000 (the original base) → 50% × 1000 = 500.
    // NOT 50% × 4000 = 2000.
    expect($discount)->toBe(500);
});

it('caps the marker discount to the current charge if the user reduces seats below the base', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'REC50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    // Apply against 1000.
    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    // User reduces something so the new net is only 600.
    $discount = $service->computeMarkerDiscount($billable->fresh(), 600);

    // min(base=1000, current=600) = 600 → 50% × 600 = 300.
    expect($discount)->toBe(300);
});

it('handles fixed discounts: caps at base_amount_net, never grows', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FIX300',
        'name' => 'Fixed 3€ off',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 300, // 3€ off
        'max_redemptions_per_billable' => 12,
    ]);

    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    // Recurring net later grows to 4000 — fixed discount stays at 300.
    expect($service->computeMarkerDiscount($billable->fresh(), 4000))->toBe(300);

    // Recurring net shrinks to 200 — fixed discount caps at 200 (no negative charge).
    expect($service->computeMarkerDiscount($billable->fresh(), 200))->toBe(200);
});
