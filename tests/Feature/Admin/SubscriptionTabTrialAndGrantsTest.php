<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The trial-extension field parsed its date-only value in app.timezone while
 * `trial_ends_at` is stored and displayed in UTC, so the tab read back a different
 * day than the operator picked and confirmed.
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
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => ['name' => 'Extra', 'prices' => ['monthly' => 100, 'yearly' => 1000]],
    ]);
});

function subTabBillable(array $attributes = []): TestBillable
{
    $b = new TestBillable;
    $b->forceFill(array_merge([
        'email' => 'sub-tab-'.uniqid().'@example.com',
        'name' => 'Sub Tab',
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->addDay(),
    ], $attributes))->save();

    return $b->refresh();
}

it('stores the picked trial date as that date in UTC, whatever app.timezone is', function (string $timezone): void {
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);

    try {
        $b = subTabBillable();
        $target = BillingTime::nowUtc()->addMonth()->format('Y-m-d');

        Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
            ->set('trialUntil', $target)
            ->call('extendTrial')
            ->assertSet('error', null)
            ->assertSet('flash', fn (?string $f) => $f !== null && str_contains($f, $target));

        expect($b->refresh()->trial_ends_at->setTimezone('UTC')->format('Y-m-d H:i'))
            ->toBe($target.' 00:00');
    } finally {
        date_default_timezone_set('UTC');
    }
})->with([
    'UTC' => ['UTC'],
    'Europe/Vienna' => ['Europe/Vienna'],
    'Asia/Tokyo' => ['Asia/Tokyo'],
]);

it('rejects a past date instead of reporting a successful extension', function (): void {
    $b = subTabBillable();
    $before = $b->trial_ends_at->toIso8601String();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('trialUntil', BillingTime::nowUtc()->subDay()->format('Y-m-d'))
        ->call('extendTrial')
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect($b->refresh()->trial_ends_at->toIso8601String())->toBe($before);
});

it('rejects a malformed date', function (): void {
    $b = subTabBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('trialUntil', 'not-a-date')
        ->call('extendTrial')
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);
});

it('clears the date field after a successful extension', function (): void {
    $b = subTabBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->set('trialUntil', BillingTime::nowUtc()->addMonth()->format('Y-m-d'))
        ->call('extendTrial')
        ->assertSet('error', null)
        ->assertSet('trialUntil', null);
});

it('persists a stable revoke reason, not a localized string', function (): void {
    $b = subTabBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'trial_ends_at' => null,
    ]);

    /** @var CouponService $service */
    $service = app(CouponService::class);
    $coupon = $service->addonGrantCoupon('GRANT-REVOKE-1', ['extra']);
    $redemption = $service->redeem($coupon, $b, []);

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
            ->call('openRevoke', $redemption->id)
            ->set('revokeNote', 'granted to the wrong org')
            ->call('revokeGrant')
            ->assertSet('error', null);

        // The operator note is appended to the stable marker, never replaces it:
        // `revoked_reason` stays queryable as `admin_revoke%` in every locale.
        expect(CouponRedemption::find($redemption->id)->revoked_reason)
            ->toBe('admin_revoke: granted to the wrong org');

        // Reset for the second locale pass.
        CouponRedemption::whereKey($redemption->id)->update(['revoked_at' => null, 'revoked_reason' => null]);
    }
});

it('refuses to revoke a grant belonging to another billable', function (): void {
    $mine = subTabBillable(['subscription_status' => SubscriptionStatus::Active, 'trial_ends_at' => null]);
    $other = subTabBillable(['subscription_status' => SubscriptionStatus::Active, 'trial_ends_at' => null]);

    /** @var CouponService $service */
    $service = app(CouponService::class);
    $coupon = $service->addonGrantCoupon('GRANT-FOREIGN-1', ['extra']);
    $redemption = $service->redeem($coupon, $other, []);

    // The foreign id is rejected before the modal even opens, and again if the
    // revoke is called anyway.
    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $mine->getKey()])
        ->call('openRevoke', $redemption->id)
        ->assertSet('error', fn (?string $e) => $e !== null)
        ->assertSet('showRevokeModal', false)
        ->assertSet('revokingId', null)
        ->set('revokeNote', 'trying anyway')
        ->call('revokeGrant')
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect(CouponRedemption::find($redemption->id)->revoked_at)->toBeNull();
});

it('locks billableId so a cancel cannot be re-pointed at another tenant', function (): void {
    $mine = subTabBillable();
    $other = subTabBillable();

    $component = Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $mine->getKey()]);

    expect(fn () => $component->set('billableId', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
