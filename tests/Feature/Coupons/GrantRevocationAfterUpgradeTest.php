<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\GrantRevoked;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

it('does not wipe local state when a paid Mollie subscription superseded the grant', function (): void {
    Event::fake([GrantRevoked::class]);

    $service = app(CouponService::class);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Upgraded Co', 'email' => 'upgraded@x.test']);

    $coupon = $service->create([
        'code' => 'GRANTUP',
        'name' => 'Grant',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'active' => true,
    ]);

    $redemption = $service->redeem($coupon, $billable, []);

    // The customer then upgraded Local → Mollie: `subscription_ends_at` becomes null.
    $billable->refresh()->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_ends_at' => null,
        'mollie_customer_id' => 'cst_up',
        'mollie_mandate_id' => 'mdt_up',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_up', 'seat_count' => 1],
    ])->save();

    $service->revokeGrant($redemption->refresh(), 'admin cleanup');

    $billable->refresh();

    // The bug: newEnd = null → the wipe branch force-filled source = None, plan_code = null,
    // status = Expired, so the customer lost all access while Mollie kept charging them and
    // the next renewal webhook arrived at a billable with no plan code.
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_up');

    // The redemption is still revoked, so the admin UI stops listing it as active.
    expect($redemption->refresh()->isRevoked())->toBeTrue();
    expect($coupon->refresh()->redemptions_count)->toBe(0);
    Event::assertDispatched(GrantRevoked::class);
});

it('still unwinds a grant that is the only thing keeping a local subscription alive', function (): void {
    $service = app(CouponService::class);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Local Co', 'email' => 'local-grant@x.test']);

    $coupon = $service->create([
        'code' => 'GRANTLOCAL',
        'name' => 'Grant',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'active' => true,
    ]);

    $redemption = $service->redeem($coupon, $billable, []);

    $service->revokeGrant($redemption->refresh(), 'mistake');

    $billable->refresh();

    expect($billable->subscription_source)->toBe(SubscriptionSource::None);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Expired);
    expect($billable->subscription_plan_code)->toBeNull();
});
