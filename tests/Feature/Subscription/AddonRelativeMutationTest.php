<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\DisableAddon;
use GraystackIT\MollieBilling\Services\Billing\EnableAddon;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    $this->app->singleton(
        \GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class,
        SpyMollieSubscriptionPatcher::class,
    );
    SpyMollieSubscriptionPatcher::$calls = [];

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['alpha', 'beta'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    foreach (['alpha', 'beta'] as $code) {
        config()->set('mollie-billing-plans.addons.'.$code, [
            'name' => ucfirst($code),
            'feature_keys' => [$code],
            'intervals' => ['monthly' => ['price_net' => 0], 'yearly' => ['price_net' => 0]],
        ]);
    }
});

function addonBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Addon Co',
        'email' => 'addon-lock@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_addon',
        'mollie_mandate_id' => 'mdt_addon',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => [],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_addon', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('adds an addon relative to the committed state, not to a stale in-memory list', function (): void {
    $billable = addonBillable();

    // A second actor enabled `alpha` after this model instance was loaded — the stale copy
    // still reports []. The read inside the lock has to see the committed value.
    $stale = $billable->replicate();
    $stale->exists = true;
    $stale->{$stale->getKeyName()} = $billable->getKey();
    $stale->setRawAttributes(array_merge($billable->getAttributes(), ['active_addon_codes' => '[]']), true);

    TestBillable::whereKey($billable->getKey())->update(['active_addon_codes' => json_encode(['alpha'])]);

    app(EnableAddon::class)->handle($stale, 'beta');

    $billable->refresh();

    // Previously the absolute list was computed from the stale [] and written as ['beta'],
    // silently disabling a just-paid-for addon and firing AddonDisabled for it.
    expect($billable->active_addon_codes)->toContain('alpha');
    expect($billable->active_addon_codes)->toContain('beta');
});

it('removes only the requested addon from the committed state', function (): void {
    $billable = addonBillable();
    $billable->forceFill(['active_addon_codes' => ['alpha', 'beta']])->save();

    app(DisableAddon::class)->handle($billable->refresh(), 'alpha');

    $billable->refresh();

    expect($billable->active_addon_codes)->toBe(['beta']);
});

it('checks an access grant minimum against the net order amount, not the gross', function (): void {
    // Pro monthly is 2900 net → 3480 gross at AT's 20%. A grant floored at 3000 net must be
    // rejected; comparing the gross admitted it.
    app(CouponService::class)->create([
        'code' => 'GRANTMIN',
        'name' => 'Grant',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'minimum_order_amount_net' => 3000,
        'active' => true,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Min Co',
        'email' => 'grant-min@x.test',
        'billing_country' => 'AT',
    ]);

    Mollie::shouldReceive('send')->never();

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3480,
        'coupon_code' => 'GRANTMIN',
    ]))->toThrow(InvalidCouponException::class);

    expect($billable->refresh()->subscription_source)->not->toBe(SubscriptionSource::Local);
});

it('accepts an access grant whose net minimum is actually met', function (): void {
    app(CouponService::class)->create([
        'code' => 'GRANTOK',
        'name' => 'Grant',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'minimum_order_amount_net' => 2900,
        'active' => true,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Ok Co',
        'email' => 'grant-ok@x.test',
        'billing_country' => 'AT',
    ]);

    Mollie::shouldReceive('send')->never();

    app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3480,
        'coupon_code' => 'GRANTOK',
    ]);

    expect($billable->refresh()->subscription_source)->toBe(SubscriptionSource::Local);
});
