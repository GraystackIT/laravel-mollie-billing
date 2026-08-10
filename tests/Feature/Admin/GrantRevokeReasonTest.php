<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Revoking an access grant takes paid-for access away immediately. It used to be a
 * one-click confirm that persisted the constant `admin_revoke` — the trail said an
 * admin did it, never why.
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

function revokeReasonBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'revoke-'.uniqid().'@example.com',
        'name' => 'Revoke Co',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
    ])->save();

    return $b->refresh();
}

function revokeReasonGrant(TestBillable $billable): CouponRedemption
{
    /** @var CouponService $service */
    $service = app(CouponService::class);
    $coupon = $service->addonGrantCoupon('GRANT-'.strtoupper(uniqid()), ['extra']);

    return $service->redeem($coupon, $billable, []);
}

it('refuses to revoke a grant without a reason', function (): void {
    $b = revokeReasonBillable();
    $redemption = revokeReasonGrant($b);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openRevoke', $redemption->id)
        ->set('revokeNote', "  \n ")
        ->call('revokeGrant')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.revoke_note_required'));

    expect(CouponRedemption::find($redemption->id)->revoked_at)->toBeNull();
    expect($b->refresh()->getActiveBillingAddonCodes())->toBe(['extra']);
});

it('carries the operator reason into the grant row and the audit trail', function (): void {
    $b = revokeReasonBillable();
    $redemption = revokeReasonGrant($b);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openRevoke', $redemption->id)
        ->set('revokeNote', '  Granted to the wrong organisation (ticket #1234)  ')
        ->call('revokeGrant')
        ->assertSet('error', null)
        ->assertSet('flash', fn (?string $f) => $f !== null)
        ->assertSet('showRevokeModal', false)
        ->assertSet('revokingId', null);

    $revoked = CouponRedemption::find($redemption->id);

    expect($revoked->revoked_at)->not->toBeNull();
    expect($revoked->revoked_reason)->toBe('admin_revoke: Granted to the wrong organisation (ticket #1234)');
    // The bare constant is what a one-click confirm used to leave behind.
    expect($revoked->revoked_reason)->not->toBe('admin_revoke');

    $entry = $b->refresh()->billingAuditTrail()->where('event', 'grant_revoked')->first();

    expect($entry)->not->toBeNull();
    expect($entry->properties['replace']['reason'] ?? null)
        ->toBe('admin_revoke: Granted to the wrong organisation (ticket #1234)');
});

it('rejects a reason longer than the persisted cap without revoking', function (): void {
    $b = revokeReasonBillable();
    $redemption = revokeReasonGrant($b);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openRevoke', $redemption->id)
        ->set('revokeNote', str_repeat('x', 201))
        ->call('revokeGrant')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.revoke_note_too_long', ['max' => 200]));

    expect(CouponRedemption::find($redemption->id)->revoked_at)->toBeNull();
});

it('locks the grant the revoke modal addresses', function (): void {
    $b = revokeReasonBillable();
    $mine = revokeReasonGrant($b);

    $other = revokeReasonBillable();
    $foreign = revokeReasonGrant($other);

    $component = Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openRevoke', $mine->id);

    expect(fn () => $component->set('revokingId', $foreign->id))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'Cannot update locked property: [revokingId]');

    expect(CouponRedemption::find($foreign->id)->revoked_at)->toBeNull();
});

it('clears a typed reason when the modal is closed', function (): void {
    $b = revokeReasonBillable();
    $redemption = revokeReasonGrant($b);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openRevoke', $redemption->id)
        ->set('revokeNote', 'typed for the first grant')
        ->set('showRevokeModal', false)
        ->assertSet('revokeNote', '')
        ->assertSet('revokingId', null);
});
