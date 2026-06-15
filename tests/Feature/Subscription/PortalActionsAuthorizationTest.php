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
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'feature_keys' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['price_net' => 990],
            'yearly' => ['price_net' => 9900],
        ],
    ]);
    config()->set('mollie-billing-plans.products.credit-pack', [
        'name' => 'Credit Pack',
        'price_net' => 2000,
        'wallet' => 'Tokens',
        'amount' => 100,
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
    // Deny by default — every mutating portal action must enforce authorization.
    MollieBilling::authUsing(fn (): bool => false);
});

it('forbids enabling an addon when authorization is denied', function (): void {
    Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'print-gateway')
        ->assertForbidden();
});

it('forbids disabling an addon when authorization is denied', function (): void {
    Livewire::test('mollie-billing::addons')
        ->call('disableAddon', 'print-gateway')
        ->assertForbidden();
});

it('forbids purchasing a product when authorization is denied', function (): void {
    Livewire::test('mollie-billing::products')
        ->call('purchase', 'credit-pack')
        ->assertForbidden();
});

it('forbids applying a plan change when authorization is denied', function (): void {
    Livewire::test('mollie-billing::plan-change')
        ->set('selectedPlan', 'pro')
        ->call('applyChange')
        ->assertForbidden();
});

it('forbids cancelling a scheduled change when authorization is denied', function (): void {
    Livewire::test('mollie-billing::plan-change')
        ->call('cancelScheduledChange')
        ->assertForbidden();
});
