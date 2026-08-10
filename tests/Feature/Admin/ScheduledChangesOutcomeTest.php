<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * apply() and cancel() return silently when there is no `scheduled_change` marker, so
 * the list used to flash "Scheduled change applied." for a pure no-op.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null],
            ],
        ],
    ]);
});

function scheduledBillable(?array $change): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'sched-'.uniqid().'@example.com',
        'name' => 'Scheduled',
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'scheduled_change_at' => BillingTime::nowUtc()->addDays(3),
        'subscription_meta' => $change === null ? [] : ['scheduled_change' => $change],
    ])->save();

    return $b->refresh();
}

it('reports a no-op instead of flashing success when nothing is scheduled', function (): void {
    // `scheduled_change_at` is set but the meta marker is gone — the row still shows
    // in the list, and apply() would return without doing anything.
    $b = scheduledBillable(null);

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('applyNow', $b->getKey())
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);
});

it('reports a no-op for cancel as well', function (): void {
    $b = scheduledBillable(null);

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('cancel', $b->getKey())
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);
});

it('confirms a real cancel', function (): void {
    $b = scheduledBillable(['plan_code' => 'pro', 'interval' => 'yearly']);

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('cancel', $b->getKey())
        ->assertSet('error', null)
        ->assertSet('flash', fn (?string $f) => $f !== null);

    $b->refresh();
    expect($b->scheduled_change_at)->toBeNull();
    expect($b->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();
});

it('reports an unknown billable rather than staying silent', function (): void {
    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->call('applyNow', 999999)
        ->assertSet('flash', null)
        ->assertSet('error', fn (?string $e) => $e !== null);
});
