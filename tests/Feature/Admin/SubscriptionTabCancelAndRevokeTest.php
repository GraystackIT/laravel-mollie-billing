<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The subscription tab's actions that go through a prompt: cancelling and revoking both
 * demand a reason, and the prompt is only opened for something this tab may act on.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_tabprompt']);
});

function tabPromptPaying(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Prompt', 'email' => uniqid('tp-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tabprompt', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_tabprompt',
        'mollie_mandate_id' => 'mdt_tabprompt',
    ])->save();

    return $billable->fresh();
}

function tabPrompt(TestBillable $billable): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()]);
}

it('opens the cancel prompt for the variant that was asked for', function (bool $immediately): void {
    tabPrompt(tabPromptPaying())
        ->call('openCancel', $immediately)
        ->assertSet('showCancelModal', true)
        ->assertSet('cancelImmediately', $immediately)
        ->assertSet('cancelNote', '');
})->with([true, false]);

it('cancels only with a reason, and records it', function (bool $immediately): void {
    $billable = tabPromptPaying();
    $tab = tabPrompt($billable)->call('openCancel', $immediately);

    $tab->call('confirmCancel')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.cancel_note_required'));
    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    $tab->set('cancelNote', str_repeat('x', 201))->call('confirmCancel')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.cancel_note_too_long', ['max' => 200]));
    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    $tab->set('cancelNote', 'Asked for by phone')->call('confirmCancel')
        ->assertSet('showCancelModal', false)
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.'.($immediately ? 'cancelled_now' : 'cancelled_grace')));

    $billable->refresh();
    $entry = \GraystackIT\MollieBilling\Support\BillingAuditEntry::model()::query()->where('event', 'subscription_cancelled')->latest('id')->first();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->isFuture())->toBe(! $immediately)
        ->and($entry->properties['replace']['reason'])->toBe('Asked for by phone');
})->with([true, false]);

it('resubscribes a subscription that is running out', function (): void {
    $billable = tabPromptPaying();
    app(CancelSubscription::class)->handle($billable, false, 'customer');

    tabPrompt($billable)->call('resubscribe')
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.resubscribed'));

    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->fresh()->getBillingSubscriptionEndsAt())->toBeNull();

    // Not cancelled: the service refuses, and the tab says so instead of throwing.
    tabPrompt($billable->fresh())->call('resubscribe')->assertNotSet('error', null);
});

it('opens the revoke prompt only for a grant of this billable that can be revoked', function (): void {
    $billable = tabPromptPaying();
    $other = tabPromptPaying();
    $service = app(AdminExtensionService::class);

    $waiver = $service->freePlanPeriods($billable, 2);
    $extension = $service->extendByDays($billable, 14);
    $foreign = $service->freePlanPeriods($other, 2);

    tabPrompt($billable)->call('openRevoke', $waiver->id)
        ->assertSet('showRevokeModal', true)->assertSet('revokingId', $waiver->id);

    // A period extension has moved a charge: nothing coherent to take back.
    tabPrompt($billable)->call('openRevoke', $extension->id)
        ->assertSet('showRevokeModal', false)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.grant_not_revocable'));

    // Somebody else's grant, and one that does not exist.
    tabPrompt($billable)->call('openRevoke', $foreign->id)
        ->assertSet('showRevokeModal', false)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.grant_foreign'));

    tabPrompt($billable)->call('openRevoke', 999999)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.grant_not_found'));
});

it('revokes only with a reason, and records it', function (): void {
    $billable = tabPromptPaying();
    $waiver = app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $tab = tabPrompt($billable)->call('openRevoke', $waiver->id);

    $tab->call('revokeGrant')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.revoke_note_required'));
    expect($waiver->fresh()->isRevoked())->toBeFalse();

    $tab->set('revokeNote', 'Given to the wrong tenant')->call('revokeGrant')
        ->assertSet('showRevokeModal', false)
        ->assertSet('error', null);

    expect($waiver->fresh()->isRevoked())->toBeTrue()
        ->and($waiver->fresh()->revoked_reason)->toBe('admin_revoke: Given to the wrong tenant')
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and(CouponRedemption::query()->whereNull('revoked_at')->count())->toBe(0);
});
