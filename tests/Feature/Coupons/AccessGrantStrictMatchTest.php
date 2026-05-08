<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway', 'sms-pack'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 200, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 2000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => ['print-gateway', 'sms-pack'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'intervals' => [
            'monthly' => ['price_net' => 500],
            'yearly' => ['price_net' => 5000],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.sms-pack', [
        'name' => 'SMS Pack',
        'intervals' => [
            'monthly' => ['price_net' => 300],
            'yearly' => ['price_net' => 3000],
        ],
    ]);
});

function grantBillable(): TestBillable
{
    return TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();
}

function makeFullGrant(array $overrides = []): \GraystackIT\MollieBilling\Models\Coupon
{
    return app(CouponService::class)->create(array_merge([
        'code' => 'ENTGRANT',
        'name' => 'Enterprise Grant',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'enterprise',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'grant_addon_codes' => ['print-gateway'],
    ], $overrides));
}

it('rejects a grant when the chosen plan does not match the grant plan', function (): void {
    makeFullGrant();
    $billable = grantBillable();

    expect(fn () => app(CouponService::class)->validate('ENTGRANT', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => [],
        'extraSeats' => 0,
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_plan_mismatch',
    );
});

it('rejects a grant when the chosen interval does not match the grant interval', function (): void {
    makeFullGrant();
    $billable = grantBillable();

    expect(fn () => app(CouponService::class)->validate('ENTGRANT', $billable, [
        'planCode' => 'enterprise',
        'interval' => 'yearly',
        'addonCodes' => [],
        'extraSeats' => 0,
        'orderAmountNet' => 50000,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_interval_mismatch',
    );
});

it('rejects a grant when an addon was selected that is not covered', function (): void {
    makeFullGrant();
    $billable = grantBillable();

    expect(fn () => app(CouponService::class)->validate('ENTGRANT', $billable, [
        'planCode' => 'enterprise',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway', 'sms-pack'],
        'extraSeats' => 0,
        'orderAmountNet' => 5800,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_addons_exceeded',
    );
});

it('rejects a grant when extra seats are selected', function (): void {
    makeFullGrant();
    $billable = grantBillable();

    expect(fn () => app(CouponService::class)->validate('ENTGRANT', $billable, [
        'planCode' => 'enterprise',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway'],
        'extraSeats' => 3,
        'orderAmountNet' => 7000,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_seats_not_supported',
    );
});

it('accepts a grant when plan, interval, addons and seats match exactly', function (): void {
    makeFullGrant();
    $billable = grantBillable();

    $coupon = app(CouponService::class)->validate('ENTGRANT', $billable, [
        'planCode' => 'enterprise',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway'],
        'extraSeats' => 0,
        'orderAmountNet' => 5500,
        'allowed_types' => [CouponType::AccessGrant],
    ]);

    expect($coupon->code)->toBe('ENTGRANT');
});

it('rejects strict-match violations on validateWithoutBillable too', function (): void {
    makeFullGrant();

    expect(fn () => app(CouponService::class)->validateWithoutBillable('ENTGRANT', [
        'planCode' => 'enterprise',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway'],
        'extraSeats' => 5,
        'orderAmountNet' => 7500,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_seats_not_supported',
    );
});

it('accepts an addon-only grant on validateWithoutBillable when no extras were chosen beyond the grant', function (): void {
    app(CouponService::class)->create([
        'code' => 'ADDONONLY',
        'name' => 'Addon-only',
        'type' => CouponType::AccessGrant,
        'grant_addon_codes' => ['print-gateway'],
    ]);

    // No grant_plan_code → plan/interval mismatch checks must NOT trigger.
    $coupon = app(CouponService::class)->validateWithoutBillable('ADDONONLY', [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway'],
        'extraSeats' => 0,
        'orderAmountNet' => 1500,
        'allowed_types' => [CouponType::AccessGrant],
    ]);

    expect($coupon->code)->toBe('ADDONONLY');
});

it('rejects an addon-only grant when an extra not covered by the grant was selected', function (): void {
    app(CouponService::class)->create([
        'code' => 'ADDONONLY',
        'name' => 'Addon-only',
        'type' => CouponType::AccessGrant,
        'grant_addon_codes' => ['print-gateway'],
    ]);

    expect(fn () => app(CouponService::class)->validateWithoutBillable('ADDONONLY', [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => ['print-gateway', 'sms-pack'],
        'extraSeats' => 0,
        'orderAmountNet' => 1800,
        'allowed_types' => [CouponType::AccessGrant],
    ]))->toThrow(
        InvalidCouponException::class,
        'grant_addons_exceeded',
    );
});

// -----------------------------------------------------------------------------
// Service-level safety net: even if the UI layer ever calls handle() with a
// stale request payload (e.g. user navigated back, changed seats/addons, hit
// submit before the UI re-validation fired), the service must still refuse
// to activate a Local subscription that exceeds the grant.
// -----------------------------------------------------------------------------

it('aborts StartSubscriptionCheckout when extra seats were added after the grant was applied', function (): void {
    makeFullGrant();

    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test', 'billing_country' => 'AT']);

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'addon_codes' => ['print-gateway'],
        'extra_seats' => 5,
        'coupon_code' => 'ENTGRANT',
        'amount_gross' => 0,
    ]))->toThrow(InvalidCouponException::class, 'grant_seats_not_supported');

    $billable->refresh();
    expect($billable->subscription_source)->not->toBe(SubscriptionSource::Local);
});

it('aborts StartSubscriptionCheckout when an uncovered addon was added after the grant was applied', function (): void {
    makeFullGrant();

    Mollie::shouldReceive('send')->never();

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test', 'billing_country' => 'AT']);

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'addon_codes' => ['print-gateway', 'sms-pack'],
        'extra_seats' => 0,
        'coupon_code' => 'ENTGRANT',
        'amount_gross' => 0,
    ]))->toThrow(InvalidCouponException::class, 'grant_addons_exceeded');

    $billable->refresh();
    expect($billable->subscription_source)->not->toBe(SubscriptionSource::Local);
});
