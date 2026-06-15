<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 5,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_123',
        'mollie_mandate_id' => 'mdt_test_123',
        'subscription_meta' => ['seat_count' => 5, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    $this->billable = $billable->refresh();

    MollieBilling::resolveBillableUsing(fn () => $this->billable);
});

it('forbids syncing seats when the host app denies authorization', function (): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::seats')
        ->call('syncSeats')
        ->assertForbidden();
});

it('allows syncing seats when the host app grants authorization', function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::seats')
        ->call('syncSeats')
        ->assertOk();
});
