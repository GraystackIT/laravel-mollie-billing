<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\ConfigSubscriptionCatalog;
use Livewire\Livewire;

// The checkout must offer what the *active catalog* sells, not what
// `mollie-billing-plans.plans` happens to contain. An app with a database-backed
// catalog has plan codes the config file never mentions — and stale config plans
// that must not show up. A second ConfigSubscriptionCatalog pointed at another
// config key stands in for such a catalog: same contract, different data source.
beforeEach(function (): void {
    // Stale configuration — nothing here may reach the checkout.
    config()->set('mollie-billing-plans.plans', [
        'stale-config-plan' => [
            'name' => 'Stale',
            'tier' => 1,
            'included_seats' => 99,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 111, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'stale-config-addon' => [
            'name' => 'Stale Addon',
            'intervals' => ['monthly' => ['price_net' => 111]],
        ],
    ]);

    config()->set('catalog-under-test.plans', [
        'db-starter' => [
            'name' => 'DB Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
        'db-pro' => [
            'name' => 'DB Pro',
            'tier' => 2,
            'included_seats' => 3,
            'feature_keys' => [],
            'allowed_addons' => ['db-extra'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2000, 'seat_price_net' => 300, 'included_usages' => []],
                'yearly' => ['base_price_net' => 20000, 'seat_price_net' => 3000, 'included_usages' => []],
            ],
        ],
        'db-internal' => [
            'name' => 'DB Internal',
            'hidden' => true,
            'tier' => 3,
            'included_seats' => 50,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('catalog-under-test.addons', [
        'db-extra' => [
            'name' => 'DB Extra',
            'intervals' => [
                'monthly' => ['price_net' => 500],
                'yearly' => ['price_net' => 5000],
            ],
        ],
    ]);

    app()->bind(
        SubscriptionCatalogInterface::class,
        fn (): SubscriptionCatalogInterface => new ConfigSubscriptionCatalog('catalog-under-test'),
    );

    MollieBilling::authUsing(fn (): bool => true);
});

it('offers the active catalog plans and ignores the plans configuration', function (): void {
    $plans = Livewire::test('mollie-billing::checkout')->instance()->plans();

    expect(array_keys($plans))->toBe(['db-starter', 'db-pro'])
        ->and($plans)->not->toHaveKey('stale-config-plan')
        ->and($plans)->not->toHaveKey('db-internal');
});

it('builds the plan card data from the catalog', function (): void {
    $plan = Livewire::test('mollie-billing::checkout')->instance()->plans()['db-pro'];

    expect($plan['name'])->toBe('DB Pro')
        ->and($plan['included_seats'])->toBe(3)
        ->and($plan['allowed_addons'])->toBe(['db-extra'])
        ->and($plan['intervals']['monthly']['base_price_net'])->toBe(2000)
        ->and($plan['intervals']['monthly']['seat_price_net'])->toBe(300)
        ->and($plan['intervals']['yearly']['base_price_net'])->toBe(20000);
});

it('pre-selects a catalog plan from the query parameter', function (): void {
    Livewire::withQueryParams(['plan' => 'db-pro'])
        ->test('mollie-billing::checkout')
        ->assertSet('plan_code', 'db-pro');
});

it('ignores a plan code that only exists in the stale configuration', function (): void {
    Livewire::withQueryParams(['plan' => 'stale-config-plan'])
        ->test('mollie-billing::checkout')
        ->assertSet('plan_code', '');
});

it('rejects a config-only plan code in the plan step validation', function (): void {
    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'stale-config-plan')
        ->set('step', 2)
        ->call('next')
        ->assertHasErrors('plan_code');
});

it('prices catalog addons in the checkout subtotal', function (): void {
    // Would silently drop the addon price if `addons()` still read the config file:
    // `db-extra` exists only in the catalog.
    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'db-pro')
        ->set('addon_codes', ['db-extra'])
        ->set('extra_seats', 2);

    expect($component->instance()->subtotalNet())->toBe(2000 + 500 + 2 * 300);
});

it('offers the addons and seats step based on the catalog', function (): void {
    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'db-starter');
    expect($component->instance()->hasAddonsOrSeatsStep())->toBeFalse();

    $component->set('plan_code', 'db-pro');
    expect($component->instance()->hasAddonsOrSeatsStep())->toBeTrue();
});
