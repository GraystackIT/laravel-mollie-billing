<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Testing\TestBillable;

// The amount Mollie holds for the recurring charge and the amount the books will honour
// have to be the same number. `recurring_discount_net` prices the Mollie subscription;
// every later renewal is reconciled against `computeMarkerDiscount()`, which stops at
// `max_redemptions_per_billable`. At the shipped default of 1 the activation *is* that one
// application — so a subscription created at the discounted amount charged a second
// discounted period the invoice booked at full net, with an `adjustment` line and a
// `PaymentAmountMismatch` every time.

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
});

function recurringCoupon(string $code, int $maxPerBillable): void
{
    app(CouponService::class)->create([
        'code' => $code,
        'name' => $code,
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions_per_billable' => $maxPerBillable,
    ]);
}

function couponBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Coupon Co',
        'email' => uniqid('coupon-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

it('does not discount the Mollie subscription when the first payment spends the only application', function (): void {
    recurringCoupon('ONCE', 1);

    $priced = app(FirstPaymentCouponPricing::class)->price(
        couponBillable(), 'ONCE', 'pro', 'monthly', [], 0,
    );

    // The first payment is discounted — that is the one application the coupon promises.
    expect((int) $priced['discount_net'])->toBe(500)
        // The recurring amount is NOT, because no application is left to honour it.
        ->and((int) $priced['recurring_discount_net'])->toBe(0);
});

it('discounts the Mollie subscription when applications remain', function (): void {
    recurringCoupon('THREE', 3);

    $priced = app(FirstPaymentCouponPricing::class)->price(
        couponBillable(), 'THREE', 'pro', 'monthly', [], 0,
    );

    expect((int) $priced['discount_net'])->toBe(500)
        ->and((int) $priced['recurring_discount_net'])->toBe(500);
});

it('keeps the recurring discount for a trial start, which spends no application', function (): void {
    // A trial's redemption row carries no discount and does not count as an application —
    // the first *paid* charge is still the first one.
    recurringCoupon('TRIALONCE', 1);

    $priced = app(FirstPaymentCouponPricing::class)->price(
        couponBillable(), 'TRIALONCE', 'pro', 'monthly', [], 0, trialStarting: true,
    );

    expect((int) $priced['recurring_discount_net'])->toBe(500);
});
