<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * An operator cancelling someone else's subscription is irreversible — a Mollie
 * subscription cannot be resumed, only recreated — and it stops money coming in. It
 * used to be a one-click `wire:confirm`, and the audit row recorded that it happened
 * but never why.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null],
            ],
        ],
    ]);
});

function cancelReasonBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'cancel-'.uniqid().'@example.com',
        'name' => 'Cancel Co',
        'billing_country' => 'AT',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ])->save();

    return $b->refresh();
}

/** @return array<string, mixed> */
function lastCancelAudit(): array
{
    $row = BillingAuditEntry::model()::query()
        ->where('event', 'subscription_cancelled')
        ->orderByDesc('id')
        ->first();

    return $row === null ? [] : (array) $row->properties['replace'];
}

it('refuses to cancel without a reason', function (): void {
    $b = cancelReasonBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', true)
        ->set('cancelNote', "  \n ")
        ->call('confirmCancel')
        ->assertSet('flash', null)
        ->assertSet('error', __('billing::admin.billables.subscription.flash.cancel_note_required'));

    expect($b->refresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(lastCancelAudit())->toBe([]);
});

it('rejects a reason longer than the cap', function (): void {
    $b = cancelReasonBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', true)
        ->set('cancelNote', str_repeat('x', 201))
        ->call('confirmCancel')
        ->assertSet('error', __('billing::admin.billables.subscription.flash.cancel_note_too_long', ['max' => 200]));

    expect($b->refresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('carries the operator reason into the audit trail on a force cancel', function (): void {
    $b = cancelReasonBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', true)
        ->set('cancelNote', 'Chargeback dispute, ticket #4711')
        ->call('confirmCancel')
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.cancelled_now'))
        ->assertSet('showCancelModal', false);

    expect($b->refresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);

    $audit = lastCancelAudit();
    expect($audit['reason'])->toBe('Chargeback dispute, ticket #4711')
        ->and($audit['immediately'])->toBeTrue();
});

it('records the reason for an end-of-period cancel too, and keeps it distinguishable', function (): void {
    $b = cancelReasonBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', false)
        ->set('cancelNote', 'Downgrading at renewal per customer request')
        ->call('confirmCancel')
        ->assertSet('flash', __('billing::admin.billables.subscription.flash.cancelled_grace'));

    $audit = lastCancelAudit();
    expect($audit['reason'])->toBe('Downgrading at renewal per customer request')
        ->and($audit['immediately'])->toBeFalse();
});

it('will not let the browser flip which cancel variant runs', function (): void {
    // openCancel() decides `cancelImmediately`, and the two variants differ in what
    // the customer keeps — an end-of-period cancel leaves access until the period
    // ends, a force cancel revokes it now and settles any open overage.
    $b = cancelReasonBillable();

    $component = Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', false);

    try {
        $component->set('cancelImmediately', true);
        $this->fail('A locked property accepted a client update.');
    } catch (CannotUpdateLockedPropertyException) {
        // expected
    }

    $component->assertSet('cancelImmediately', false);
});

it('trims the recorded reason', function (): void {
    $b = cancelReasonBillable();

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $b->getKey()])
        ->call('openCancel', true)
        ->set('cancelNote', "   Fraud review   \n")
        ->call('confirmCancel');

    expect(lastCancelAudit()['reason'])->toBe('Fraud review');
});
