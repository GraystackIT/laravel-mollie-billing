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

// ── A grant whose subscription has ended ─────────────────────────────────────
//
// The redemption of a gift that ran out stays un-revoked and listed, with a revoke
// button. Revoking it acted on whatever subscription the tenant had by then: the free
// plan they signed up for themselves was wiped to Expired, a later gift lost the first
// one's days.

/** GIFT30 redeemed, run out, and expired by the nightly pass. */
function revocationExpiredGift(): array
{
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => uniqid('gone-').'@y.test'])->refresh();

    $redemption = $service->redeem($service->accessGrantCoupon('GIFT30-'.bin2hex(random_bytes(2)), 'pro', 'monthly', [], 30), $billable, []);

    test()->travel(31)->days();
    app()->call([new \GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob, 'handle']);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);

    return [$billable->fresh(), $redemption->fresh()];
}

it('leaves the free plan a tenant took after the gift ran out when the gift is revoked', function (): void {
    [$billable, $gift] = revocationExpiredGift();

    app(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');

    app(CouponService::class)->revokeGrant($gift->fresh(), 'audit');
    $billable->refresh();

    expect($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionEndsAt())->toBeNull()
        ->and($gift->fresh()->isRevoked())->toBeTrue();
});

it('takes nothing off a later gift when a gift that ran out is revoked', function (): void {
    [$billable, $gift] = revocationExpiredGift();

    $service = app(CouponService::class);
    $second = $service->redeem($service->accessGrantCoupon('GIFT60', 'pro', 'monthly', [], 60), $billable, []);
    $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt()?->toIso8601String();

    $service->revokeGrant($gift->fresh(), 'audit');
    $billable->refresh();

    expect($billable->getBillingSubscriptionEndsAt()?->toIso8601String())->toBe($endsAt)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('pro')
        ->and($second->fresh()->isRevoked())->toBeFalse();
});

it('still takes its days off a running subscription that a second gift extended', function (): void {
    // Both gifts on one subscription: revoking the first takes its 30 days back.
    $service = app(CouponService::class);
    $billable = TestBillable::create(['name' => 'X', 'email' => 'both@y.test'])->refresh();

    $first = $service->redeem($service->accessGrantCoupon('GIFT-ONE', 'pro', 'monthly', [], 30), $billable, []);
    $this->travel(10)->days();
    $service->redeem($service->accessGrantCoupon('GIFT-TWO', 'pro', 'monthly', [], 60), $billable->fresh(), []);
    $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt();

    $service->revokeGrant($first->fresh(), 'audit');

    expect($billable->fresh()->getBillingSubscriptionEndsAt()?->toIso8601String())
        ->toBe($endsAt->copy()->subDays(30)->toIso8601String());
});
