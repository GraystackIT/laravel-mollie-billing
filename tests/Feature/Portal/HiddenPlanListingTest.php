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
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.supplier', [
        'name' => 'Supplier',
        'hidden' => true,
        'tier' => 3,
        'included_seats' => 25,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 5000], 'usage_overage_prices' => ['Tokens' => false]],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 60000], 'usage_overage_prices' => ['Tokens' => false]],
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function makeListingBillable(string $planCode, SubscriptionSource $source = SubscriptionSource::Mollie): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => $source,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $planCode,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => $source === SubscriptionSource::Mollie ? 'cst_test_123' : null,
        'mollie_mandate_id' => $source === SubscriptionSource::Mollie ? 'mdt_test_123' : null,
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

it('omits hidden plans from the checkout plan step', function (): void {
    $plans = Livewire::test('mollie-billing::checkout')->instance()->plans();

    expect($plans)->toHaveKey('pro')
        ->and($plans)->not->toHaveKey('supplier');
});

it('does not pre-select a hidden plan passed via the plan query parameter', function (): void {
    $component = Livewire::withQueryParams(['plan' => 'supplier'])
        ->test('mollie-billing::checkout');

    $component->assertSet('plan_code', '');
});

it('still pre-selects a visible plan passed via the plan query parameter', function (): void {
    $component = Livewire::withQueryParams(['plan' => 'pro'])
        ->test('mollie-billing::checkout');

    $component->assertSet('plan_code', 'pro');
});

it('rejects a hidden plan code in the checkout plan step validation', function (): void {
    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'supplier')
        ->set('step', 2)
        ->call('next')
        ->assertHasErrors('plan_code');
});

it('omits hidden plans from the plan-change screen', function (): void {
    makeListingBillable('pro');

    $plans = Livewire::test('mollie-billing::plan-change')->instance()->selectablePlans();

    expect($plans)->toContain('pro')
        ->and($plans)->not->toContain('supplier');
});

it('keeps the subscriber own hidden plan on the plan-change screen', function (): void {
    // Without this the supplier would lose the "current" badge and have no way to
    // trigger the interval switch that stays allowed within a hidden plan.
    makeListingBillable('supplier', SubscriptionSource::Local);

    $plans = Livewire::test('mollie-billing::plan-change')->instance()->selectablePlans();

    expect($plans)->toContain('supplier')
        ->and($plans)->toContain('pro');
});

it('ignores a preview request for a hidden plan the billable is not on', function (): void {
    makeListingBillable('pro');

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'supplier')
        ->assertSet('selectedPlan', null)
        ->assertSet('preview', []);
});

it('previews the billable own hidden plan', function (): void {
    makeListingBillable('supplier', SubscriptionSource::Local);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'supplier')
        ->assertSet('selectedPlan', 'supplier');
});
