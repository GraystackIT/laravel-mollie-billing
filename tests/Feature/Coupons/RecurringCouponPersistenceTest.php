<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('writes the active_recurring_coupon marker on initial redeem', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    // 3 monthly periods × 30d + 1d buffer = 91 days from now.
    $expectedValidUntil = BillingTime::nowUtc()->copy()->addDays(91);

    expect($marker)->not->toBeNull()
        ->and($marker['coupon_id'])->toBe($coupon->id)
        ->and($marker['code'])->toBe('REC10')
        ->and($marker['discount_type'])->toBe('percentage')
        ->and($marker['discount_value'])->toBe(10)
        ->and(Carbon::parse($marker['valid_until'])->diffInSeconds($expectedValidUntil, true))->toBeLessThan(5);
});

it('rejects a second recurring coupon while a marker is still active', function (): void {
    $service = app(CouponService::class);

    $first = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);
    $service->create([
        'code' => 'rec20',
        'name' => 'Recurring 20%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($first, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect(fn () => $service->validate('REC20', $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => [],
        'orderAmountNet' => 1000,
    ]))->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidCouponException::class);
});

it('computeMarkerDiscount applies the marker discount as long as the marker is redeemable', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect($service->computeMarkerDiscount($billable->fresh(), 1000))->toBe(100);
});

it('computeMarkerDiscount returns 0 once the marker valid_until is in the past', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 1,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    // 1 monthly period × 30d + 1d buffer = 31 days lifetime. Time-travel past it.
    Carbon::setTestNow(Carbon::now()->addDays(32));

    expect($service->computeMarkerDiscount($billable->fresh(), 1000))->toBe(0)
        ->and($service->markerExpired($billable->fresh()))->toBeTrue();

    Carbon::setTestNow();
});

it('redeemRecurringCouponForRenewal writes a redemption (no counter mutation)', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);

    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    $markerBefore = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    // Simulate a renewal apply.
    $invoice = \GraystackIT\MollieBilling\Models\BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'serial_number' => 'INV-TEST-1',
        'invoice_kind' => \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription,
        'amount_net' => 1000,
        'amount_vat' => 0,
        'amount_gross' => 1000,
        'status' => 'paid',
        'country' => 'AT',
        'currency' => 'EUR',
        'line_items' => [],
    ]);

    $service->redeemRecurringCouponForRenewal($billable->fresh(), 1000, (int) $invoice->id);

    $markerAfter = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    // Marker is unchanged — lifetime is fully encoded in valid_until at apply time,
    // renewals only write redemption rows for audit.
    expect($markerAfter)->toBe($markerBefore);

    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(2);
});

it('marker expires when valid_until is in the past', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'rec10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 99,
        'valid_until' => Carbon::now()->addDay()->toIso8601String(),
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $service->redeem($coupon, $billable->fresh(), [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    expect($service->markerExpired($billable->fresh()))->toBeTrue();

    Carbon::setTestNow();
});
