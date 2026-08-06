<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The "Issue grant" screen used to redeem without calling CouponService::validate(),
 * so every AccessGrant guard was bypassed — most damagingly an addon grant landing on
 * a live Mollie subscription.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null],
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => [
                'monthly' => ['base_price_net' => 500, 'seat_price_net' => null],
                'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => ['name' => 'Extra', 'prices' => ['monthly' => 100, 'yearly' => 1000]],
    ]);
});

function adminGrantBillable(array $attributes = []): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'email' => 'grant-'.uniqid().'@example.com',
        'name' => 'Grant Target',
    ], $attributes))->save();

    return $b->refresh();
}

function issueGrant(TestBillable $billable, array $state = []): Testable
{
    $component = Livewire::test('mollie-billing::admin.grants.issue', [
        'routeParameters' => ['billable' => $billable->getKey()],
    ]);

    foreach ($state as $key => $value) {
        $component->set($key, $value);
    }

    return $component->call('issue');
}

it('refuses a full grant on a live Mollie subscription', function (): void {
    $b = adminGrantBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_live_1',
        'mollie_mandate_id' => 'mdt_live_1',
    ]);

    issueGrant($b, ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => 30])
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect(CouponRedemption::count())->toBe(0);
    $b->refresh();
    expect($b->subscription_source)->toBe(SubscriptionSource::Mollie);
});

it('refuses an addon-only grant on a live Mollie subscription', function (): void {
    $b = adminGrantBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_live_2',
        'mollie_mandate_id' => 'mdt_live_2',
    ]);

    issueGrant($b, ['mode' => 'addon', 'addonCodes' => ['extra']])
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect(CouponRedemption::count())->toBe(0);
    expect($b->refresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('refuses an addon-only grant when there is no active local subscription', function (): void {
    $b = adminGrantBillable();

    issueGrant($b, ['mode' => 'addon', 'addonCodes' => ['extra']])
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect(CouponRedemption::count())->toBe(0);
});

it('refuses a full grant whose plan does not match the active local plan', function (): void {
    $b = adminGrantBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ]);

    issueGrant($b, ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => 30])
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect(CouponRedemption::count())->toBe(0);
    expect($b->refresh()->subscription_plan_code)->toBe('starter');
});

it('discards the coupon it created for a rejected grant', function (): void {
    $b = adminGrantBillable([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_live_3',
        'mollie_mandate_id' => 'mdt_live_3',
    ]);

    issueGrant($b, ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => 30]);

    expect(Coupon::where('type', CouponType::AccessGrant)->count())->toBe(0);
});

it('issues a full grant on a billable without a subscription', function (): void {
    $b = adminGrantBillable();

    issueGrant($b, ['mode' => 'full', 'planCode' => 'pro', 'interval' => 'monthly', 'durationDays' => 30])
        ->assertSet('error', null);

    expect(CouponRedemption::count())->toBe(1);
    expect($b->refresh()->subscription_plan_code)->toBe('pro');
});

it('derives the coupon code from a locked mount-time nonce so a double submit cannot stack grants', function (): void {
    $b = adminGrantBillable();

    $component = Livewire::test('mollie-billing::admin.grants.issue', [
        'routeParameters' => ['billable' => $b->getKey()],
    ])->set('mode', 'full')->set('planCode', 'pro')->set('interval', 'monthly')->set('durationDays', 30);

    $nonce = $component->get('nonce');
    expect($nonce)->toBeString()->not->toBe('');

    $component->call('issue');
    expect(CouponRedemption::count())->toBe(1);
    $endsAfterFirst = $b->refresh()->subscription_ends_at;

    // Replaying the same submission must not create a second coupon/redemption:
    // the code is unchanged, so the unique index rejects it.
    $component->call('issue');

    expect(CouponRedemption::count())->toBe(1);
    expect(Coupon::where('code', 'GRANT-'.$nonce)->count())->toBe(1);
    expect($b->refresh()->subscription_ends_at?->toIso8601String())
        ->toBe($endsAfterFirst?->toIso8601String());
});

it('locks billableId so the grant cannot be redirected to another tenant', function (): void {
    $mine = adminGrantBillable();
    $other = adminGrantBillable();

    $component = Livewire::test('mollie-billing::admin.grants.issue', [
        'routeParameters' => ['billable' => $mine->getKey()],
    ]);

    expect(fn () => $component->set('billableId', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
