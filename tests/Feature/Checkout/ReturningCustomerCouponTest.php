<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

// Checkout validated every coupon with `validateWithoutBillable()` — right for a signup,
// wrong for a customer coming back: the per-billable limit is the one rule that needs the
// billable, so a coupon this customer had already used up priced the payment anyway. The
// webhook then rejected it (it does validate per billable), booked an adjustment line for
// the shortfall and activated the subscription at the discounted price regardless.

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'solo' => [
            'name' => 'Solo',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);
});

function returningCustomerWithSpentCoupon(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Returning Co',
        'email' => uniqid('returning-').'@x.test',
        'billing_country' => 'AT',
        'billing_street' => 'Teststr. 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Wien',
    ]);

    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'WELCOME',
        'name' => 'Welcome',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 1,
        'active' => true,
    ]);

    // Their one redemption, from the first subscription.
    $service->redeem($coupon, $billable, ['discount_amount_net' => 500]);

    return $billable->refresh();
}

it('refuses a coupon the returning customer has already used up before charging them', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $billable = returningCustomerWithSpentCoupon();

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'solo',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'coupon_code' => 'WELCOME',
        'amount_gross' => 600,
    ]))->toThrow(InvalidCouponException::class);
});

it('rejects the spent coupon in the checkout component for a returning customer', function (): void {
    $billable = returningCustomerWithSpentCoupon();

    MollieBilling::resolveBillableUsing(fn () => $billable);
    MollieBilling::authUsing(fn (): bool => true);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'solo')
        ->set('interval', 'monthly')
        ->set('coupon_input', 'WELCOME')
        ->call('applyCoupon');

    expect($component->get('applied_coupon'))->toBeNull()
        ->and($component->get('couponError'))->not->toBeNull();
});

it('still accepts the coupon for a customer who has not used it', function (): void {
    app(CouponService::class)->create([
        'code' => 'FRESH',
        'name' => 'Fresh',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 1,
        'active' => true,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Other Co',
        'email' => uniqid('other-').'@x.test',
        'billing_country' => 'AT',
        'billing_street' => 'Teststr. 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Wien',
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);
    MollieBilling::authUsing(fn (): bool => true);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'solo')
        ->set('interval', 'monthly')
        ->set('coupon_input', 'FRESH')
        ->call('applyCoupon');

    expect($component->get('couponError'))->toBeNull()
        ->and($component->get('applied_coupon'))->not->toBeNull();
});
