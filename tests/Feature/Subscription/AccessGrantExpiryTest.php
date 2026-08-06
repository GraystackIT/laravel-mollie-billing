<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['pro-feature'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => 500, 'included_usages' => ['emails' => 100]],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => 5000, 'included_usages' => ['emails' => 1200]],
        ],
    ]);
});

it('stops granting access once an Active duration-limited grant has expired', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Grant Co', 'email' => 'expiry@x.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'monthly', [], 30);
    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->hasAccessibleBillingSubscription())->toBeTrue();

    // Day 31: status is still Active — nothing flips it, so `ends_at` has to be the gate.
    $billable->forceFill(['subscription_ends_at' => BillingTime::nowUtc()->subDay()])->save();

    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->hasAccessibleBillingSubscription())->toBeFalse();

    // Note: FeatureAccess resolves features from the plan code alone and never consults
    // this gate, so `hasPlanFeature()` still answers true here. Closing that is a
    // FeatureAccess change (plus the status sweep that clears the plan code).
});

it('leaves an Active subscription without an expiry accessible', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Unlimited Co', 'email' => 'unlimited@x.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'monthly');
    $billable->refresh();

    expect($billable->subscription_ends_at)->toBeNull();
    expect($billable->hasAccessibleBillingSubscription())->toBeTrue();
});

it('keeps a Mollie subscription accessible because it never carries an ends_at', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Mollie Co', 'email' => 'mollie-access@x.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(400),
        'subscription_ends_at' => null,
    ])->save();

    expect($billable->refresh()->hasAccessibleBillingSubscription())->toBeTrue();
});

it('expires an access-grant coupon subscription after its duration', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Coupon Grant', 'email' => 'coupon-grant@x.test']);

    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'GRANT30',
        'name' => 'Grant 30',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'active' => true,
    ]);

    $service->redeem($coupon, $billable, []);
    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_ends_at)->not->toBeNull();
    expect($billable->hasAccessibleBillingSubscription())->toBeTrue();

    $billable->forceFill(['subscription_ends_at' => BillingTime::nowUtc()->subMinute()])->save();

    // Without this the customer also could not re-checkout: StartSubscriptionCheckout
    // throws "already has an active subscription" while the gate stays true.
    expect($billable->refresh()->hasAccessibleBillingSubscription())->toBeFalse();
});

it('resets plan-scoped meta when a new local subscription is activated', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Stale Co', 'email' => 'stale-meta@x.test']);

    // Leftovers from a previous paid subscription on a bigger tier.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::None,
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_meta' => [
            'seat_count' => 10,
            'active_recurring_coupon' => ['coupon_id' => 1, 'code' => 'OLD', 'discount_type' => 'percentage', 'discount_value' => 50],
            'scheduled_change' => ['plan_code' => 'pro'],
            'next_charge_date_override' => BillingTime::nowUtc()->addYear()->toIso8601String(),
            'mollie_subscription_id' => 'sub_keep_me',
        ],
        'scheduled_change_at' => BillingTime::nowUtc()->addDay(),
    ])->save();

    app(ActivateLocalSubscription::class)->handle($billable->refresh(), 'pro', 'monthly', [], 30);

    $billable->refresh();
    $meta = $billable->getBillingSubscriptionMeta();

    // Pro's included_seats is 1 — 10 paid seats must not survive onto the new tier.
    expect($meta['seat_count'])->toBe(1);
    expect($meta)->not->toHaveKey('active_recurring_coupon');
    expect($meta)->not->toHaveKey('scheduled_change');
    expect($meta)->not->toHaveKey('next_charge_date_override');
    expect($billable->scheduled_change_at)->toBeNull();
    // Identity keys stay: dropping this would orphan a live Mollie subscription.
    expect($meta['mollie_subscription_id'])->toBe('sub_keep_me');
});
