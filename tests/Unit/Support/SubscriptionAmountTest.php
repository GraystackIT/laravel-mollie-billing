<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['extra-seats-pack'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2900,
                'seat_price_net' => 990,
                'included_usages' => [],
                'usage_overage_prices' => [],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.extra-seats-pack', [
        'name' => 'Extra Seats Pack',
        'feature_keys' => [],
        'intervals' => [
            'monthly' => ['price_net' => 500],
        ],
    ]);
});

it('computes net for plan + extra seats + addons', function (): void {
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@example.com']);
    $catalog = app(SubscriptionCatalogInterface::class);

    // 1 included seat, 3 seats requested → 2 extra seats
    $net = SubscriptionAmount::net($catalog, $billable, 'pro', 'monthly', 3, ['extra-seats-pack']);

    // 2900 (base) + 2*990 (extra seats) + 500 (addon, qty 1)
    expect($net)->toBe(2900 + 1980 + 500);
});

it('honours getBillingAddonQuantity > 1 for net', function (): void {
    $billable = new class extends TestBillable {
        public function getBillingAddonQuantity(string $addonCode): int
        {
            return $addonCode === 'extra-seats-pack' ? 3 : 0;
        }
    };
    $billable->name = 'X';
    $billable->email = 'x@example.com';
    $billable->save();
    $catalog = app(SubscriptionCatalogInterface::class);

    $net = SubscriptionAmount::net($catalog, $billable, 'pro', 'monthly', 1, ['extra-seats-pack']);

    // 2900 (base) + 0 extra seats + 3 * 500 (addon qty 3)
    expect($net)->toBe(2900 + 1500);
});

it('returns line items for plan + extra seats + addons', function (): void {
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@example.com']);
    $catalog = app(SubscriptionCatalogInterface::class);

    $items = SubscriptionAmount::lineItems($catalog, $billable, 'pro', 'monthly', 2, ['extra-seats-pack']);

    expect($items)->toHaveCount(3);
    expect($items[0]['kind'])->toBe('plan');
    expect($items[0]['total_net'])->toBe(2900);
    expect($items[1]['kind'])->toBe('seat');
    expect($items[1]['quantity'])->toBe(2);
    expect($items[1]['total_net'])->toBe(1980);
    expect($items[2]['kind'])->toBe('addon');
    expect($items[2]['total_net'])->toBe(500);
});

it('honours getBillingAddonQuantity > 1 for line items', function (): void {
    $billable = new class extends TestBillable {
        public function getBillingAddonQuantity(string $addonCode): int
        {
            return $addonCode === 'extra-seats-pack' ? 4 : 0;
        }
    };
    $billable->name = 'X';
    $billable->email = 'x@example.com';
    $billable->save();
    $catalog = app(SubscriptionCatalogInterface::class);

    $items = SubscriptionAmount::lineItems($catalog, $billable, 'pro', 'monthly', 0, ['extra-seats-pack']);

    $addon = collect($items)->firstWhere('kind', 'addon');
    expect($addon['quantity'])->toBe(4);
    expect($addon['total_net'])->toBe(2000);
});

it('returns 0 for empty plan code', function (): void {
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@example.com']);
    $catalog = app(SubscriptionCatalogInterface::class);

    expect(SubscriptionAmount::net($catalog, $billable, '', 'monthly', 1, []))->toBe(0);
});
