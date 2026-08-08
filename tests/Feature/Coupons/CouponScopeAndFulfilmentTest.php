<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * A coupon's scope filters and what happens when a fully-covered order cannot record
 * its redemption.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['extra'],
        'intervals' => ['monthly' => ['base_price_net' => 50000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 500]],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.products.token-pack', [
        'name' => 'Token pack',
        'price_net' => 1000,
        'usage_type' => 'Tokens',
        'quantity' => 500,
    ]);
});

function scopeBillable(): TestBillable
{
    /** @var TestBillable $b */
    return TestBillable::create([
        'name' => 'Scope Co',
        'email' => uniqid('scope-').'@x.test',
        'billing_country' => 'DE',
    ])->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 10a — a set filter is a requirement, not a conditional
// ─────────────────────────────────────────────────────────────────────────────

it('refuses a product-restricted coupon on a subscription order', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'TOKENS50',
        'name' => 'Half off the token pack',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'applicable_products' => ['token-pack'],
    ]);

    $billable = scopeBillable();

    // Subscription checkout passes no `productCodes`, so the filter never ran and this
    // €500/yr signup was halved by a coupon meant for a €10 pack.
    expect(fn () => $service->validate('TOKENS50', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 50000,
        'allowed_types' => [CouponType::SinglePayment],
    ]))->toThrow(InvalidCouponException::class);
});

it('refuses a plan-restricted coupon on a product order', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'PRO50',
        'name' => 'Half off Pro',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'applicable_plans' => ['pro'],
    ]);

    $billable = scopeBillable();

    // The mirror image: a product purchase passes no `planCode`.
    expect(fn () => $service->validate('PRO50', $billable, [
        'productCodes' => ['token-pack'],
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::SinglePayment],
    ]))->toThrow(InvalidCouponException::class);
});

it('refuses an interval-restricted coupon when no interval is named', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'YEARLYONLY',
        'name' => 'Yearly only',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'applicable_intervals' => ['yearly'],
    ]);

    $billable = scopeBillable();

    expect(fn () => $service->validate('YEARLYONLY', $billable, [
        'productCodes' => ['token-pack'],
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::SinglePayment],
    ]))->toThrow(InvalidCouponException::class);
});

it('refuses an addon-restricted coupon on an order with no addons', function (): void {
    $service = app(CouponService::class);
    $service->create([
        'code' => 'ADDONONLY',
        'name' => 'Addon only',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'applicable_addons' => ['extra'],
    ]);

    $billable = scopeBillable();

    expect(fn () => $service->validate('ADDONONLY', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => [],
        'orderAmountNet' => 50000,
        'allowed_types' => [CouponType::SinglePayment],
    ]))->toThrow(InvalidCouponException::class);
});

it('still accepts each restricted coupon where it belongs', function (): void {
    $service = app(CouponService::class);

    foreach ([
        ['code' => 'TOKENS50', 'applicable_products' => ['token-pack']],
        ['code' => 'PRO50', 'applicable_plans' => ['pro']],
        ['code' => 'MONTHLYONLY', 'applicable_intervals' => ['monthly']],
        ['code' => 'ADDONONLY', 'applicable_addons' => ['extra']],
    ] as $extra) {
        $service->create(array_merge([
            'name' => $extra['code'],
            'type' => CouponType::SinglePayment,
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 10,
        ], $extra));
    }

    $billable = scopeBillable();

    // The point of the fix is a requirement, not a blanket refusal.
    expect($service->validate('TOKENS50', $billable, [
        'productCodes' => ['token-pack'],
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::SinglePayment],
    ])->code)->toBe('TOKENS50');

    expect($service->validate('PRO50', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 50000,
        'allowed_types' => [CouponType::SinglePayment],
    ])->code)->toBe('PRO50');

    expect($service->validate('MONTHLYONLY', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'orderAmountNet' => 50000,
        'allowed_types' => [CouponType::SinglePayment],
    ])->code)->toBe('MONTHLYONLY');

    expect($service->validate('ADDONONLY', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => ['extra'],
        'orderAmountNet' => 50500,
        'allowed_types' => [CouponType::SinglePayment],
    ])->code)->toBe('ADDONONLY');
});

// ─────────────────────────────────────────────────────────────────────────────
// 10b — a fully covered order must not be fulfilled without its redemption
// ─────────────────────────────────────────────────────────────────────────────

it('does not deliver a fully covered order when the redemption is refused', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'PACKFREE',
        'name' => 'Free pack',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 1,
        'applicable_products' => ['token-pack'],
    ]);

    $billable = scopeBillable();

    // The slot is already gone — the state a second tab finds after the first one won
    // the coupon lock. validate() is lock-free, so both tabs get this far.
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'discount_amount_net' => 1000,
        'applied_at' => now(),
    ]);

    $resolved = [['coupon' => $coupon->fresh(), 'discount' => 1000]];

    // Reached through the private inline path the same way the service does.
    $reflection = new ReflectionMethod(StartOneTimeOrderCheckout::class, 'completeInline');
    $reflection->setAccessible(true);

    expect(fn () => $reflection->invoke(
        app(StartOneTimeOrderCheckout::class),
        $billable,
        'token-pack',
        1000,
        $resolved,
    ))->toThrow(InvalidCouponException::class);

    // Nothing delivered and no invoice left behind: inline means the coupon IS the
    // consideration, so a redemption that cannot be recorded is goods given away.
    expect(BillingInvoice::query()->count())->toBe(0)
        ->and((int) ($billable->fresh()->getWallet('Tokens')?->balanceInt ?? 0))->toBe(0);
});

it('delivers a fully covered order when the redemption succeeds', function (): void {
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'PACKFREE',
        'name' => 'Free pack',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 1,
        'applicable_products' => ['token-pack'],
    ]);

    $billable = scopeBillable();

    $reflection = new ReflectionMethod(StartOneTimeOrderCheckout::class, 'completeInline');
    $reflection->setAccessible(true);

    $result = $reflection->invoke(
        app(StartOneTimeOrderCheckout::class),
        $billable,
        'token-pack',
        1000,
        [['coupon' => $coupon, 'discount' => 1000]],
    );

    // The ordinary path is untouched: a 0-EUR audit invoice, the redemption recorded and
    // the wallet credited.
    expect($result['completed'])->toBeTrue()
        ->and((int) BillingInvoice::query()->sole()->amount_gross)->toBe(0)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1)
        ->and((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(500);
});
