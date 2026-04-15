<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\GrantRevoked;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['turbo'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.turbo', [
        'name' => 'Turbo',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['base_price_net' => 500]],
    ]);
});

it('revokes a full access grant and resets the subscription when no time remains', function (): void {
    Event::fake([GrantRevoked::class]);
    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();

    $coupon = $service->accessGrantCoupon('GRANT-A', 'pro', 'monthly', [], 30);
    $redemption = $service->redeem($coupon, $billable, []);

    $billable->refresh();
    expect($billable->subscription_plan_code)->toBe('pro');

    $service->revokeGrant($redemption->fresh(), 'admin revoke');
    $billable->refresh();

    expect($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::None->value)
        ->and($billable->subscription_plan_code)->toBeNull()
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Expired);

    expect($redemption->fresh()->revoked_at)->not->toBeNull();
    expect($coupon->fresh()->redemptions_count)->toBe(0);
    Event::assertDispatched(GrantRevoked::class);
});

it('revokes an addon-only grant and removes the addon', function (): void {
    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();

    // Need an active local subscription for addon-only grants.
    $planCoupon = $service->accessGrantCoupon('GRANT-BASE', 'pro', 'monthly', [], 30);
    $service->redeem($planCoupon, $billable, []);
    $billable->refresh();

    $addonCoupon = $service->addonGrantCoupon('GRANT-ADDON', ['turbo']);
    $addonRedemption = $service->redeem($addonCoupon, $billable, []);

    $billable->refresh();
    expect($billable->getActiveBillingAddonCodes())->toContain('turbo');

    $service->revokeGrant($addonRedemption->fresh(), null);
    $billable->refresh();

    expect($billable->getActiveBillingAddonCodes())->not->toContain('turbo');
});

it('is idempotent when the redemption is already revoked', function (): void {
    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();

    $coupon = $service->accessGrantCoupon('GRANT-B', 'pro', 'monthly', [], 30);
    $redemption = $service->redeem($coupon, $billable, []);

    $service->revokeGrant($redemption->fresh(), null);
    $service->revokeGrant($redemption->fresh(), null); // no-op

    expect($coupon->fresh()->redemptions_count)->toBe(0);
});

it('rejects revoking a non-grant redemption', function (): void {
    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();

    $coupon = $service->trialExtensionCoupon('TRIAL-A', 7);
    $redemption = $service->redeem($coupon, $billable, []);

    $service->revokeGrant($redemption->fresh(), null);
})->throws(InvalidArgumentException::class);
