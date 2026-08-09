<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Testing\TestBillable;

// Mirrors the baseline setup from CheckConfigCommandTest, then adds focused
// scenarios around `hidden` plans and explicit `overage => false` declarations.
beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice);
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC');
    config()->set('mollie-billing.invoices.serial_number.prefix', [
        'invoice' => 'IN',
        'credit_note' => 'CR',
        'one_time_order' => 'OT',
    ]);
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'ACME GmbH',
        'name' => 'Jane Doe',
        'email' => 'billing@acme.test',
        'phone' => '+43 123',
        'tax_number' => 'ATU12345678',
        'address' => [
            'street' => 'Main 1',
            'city' => 'Vienna',
            'postal_code' => '1010',
            'state' => null,
            'country' => 'AT',
        ],
    ]);
    config()->set('mollie-billing.checkout_countries', [
        'regions' => ['EU'],
        'include' => [],
        'exclude' => [],
    ]);
    config()->set('mollie-billing.ip_geolocation', [
        'driver' => 'null',
        'drivers' => ['null' => []],
    ]);
    config()->set('mollie-billing.billing_timezone', 'UTC');
    config()->set('mollie-billing.overage_job_time', '02:00');
    config()->set('mollie-billing.usage_threshold_percent', 80);

    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => ['dashboard'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 1000,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 100],
                    'usage_overage_prices' => ['Tokens' => 5],
                ],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.features', [
        'dashboard' => ['name' => 'Dashboard', 'description' => null],
    ]);
    config()->set('mollie-billing-plans.addons', []);
    config()->set('mollie-billing-plans.product_groups', []);
    config()->set('mollie-billing-plans.products', []);
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => false],
    ]);
});

/** @return array<string, mixed> */
function hiddenSupplierPlan(int $tier = 3, mixed $overage = false): array
{
    return [
        'name' => 'Supplier',
        'hidden' => true,
        'tier' => $tier,
        'included_seats' => 25,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 0,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 5000],
                'usage_overage_prices' => ['Tokens' => $overage],
            ],
        ],
    ];
}

it('accepts a hidden plan alongside a visible one', function (): void {
    config()->set('mollie-billing-plans.plans.supplier', hiddenSupplierPlan());

    $this->artisan('billing:check-config')->assertExitCode(0);
});

it('errors when every plan is hidden', function (): void {
    config()->set('mollie-billing-plans.plans.pro.hidden', true);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('every plan is marked `hidden`')
        ->assertExitCode(1);
});

it('errors when hidden is not a boolean', function (): void {
    config()->set('mollie-billing-plans.plans.pro.hidden', 'yes');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('hidden must be a boolean')
        ->assertExitCode(1);
});

it('does not warn about an ambiguous tier when the collision involves a hidden plan', function (): void {
    // Supplier deliberately mirrors pro's tier — it is not part of the offered
    // ladder, so its ranking cannot be ambiguous.
    config()->set('mollie-billing-plans.plans.supplier', hiddenSupplierPlan(tier: 2));

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('is shared by multiple plans')
        ->assertExitCode(0);
});

it('still warns about an ambiguous tier between two visible plans', function (): void {
    $plan = hiddenSupplierPlan(tier: 2);
    $plan['hidden'] = false;
    config()->set('mollie-billing-plans.plans.supplier', $plan);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('is shared by multiple plans')
        ->assertExitCode(0);
});

it('suppresses the missing-overage-price warning when overage is explicitly false', function (): void {
    config()->set('mollie-billing-plans.plans.supplier', hiddenSupplierPlan());

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('overages cannot be charged')
        ->assertExitCode(0);
});

it('still warns when a quota has neither an overage price nor an explicit false', function (): void {
    $plan = hiddenSupplierPlan();
    $plan['intervals']['monthly']['usage_overage_prices'] = [];
    config()->set('mollie-billing-plans.plans.supplier', $plan);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('overages cannot be charged')
        ->assertExitCode(0);
});

it('does not warn about a missing quota for an explicit overage => false entry', function (): void {
    $plan = hiddenSupplierPlan();
    $plan['intervals']['monthly']['included_usages'] = [];
    config()->set('mollie-billing-plans.plans.supplier', $plan);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('quota will default to 0')
        ->assertExitCode(0);
});

it('rejects a non-integer overage price that is not the explicit false', function (): void {
    config()->set('mollie-billing-plans.plans.supplier', hiddenSupplierPlan(overage: true));

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('must be a non-negative integer, or `false`')
        ->assertExitCode(1);
});

it('does not fail a config whose plans simply declare no tier', function (): void {
    // `tier` is documented as being for the consuming app's own use — the package derives
    // up/downgrade from the prorata amounts and never reads it. A config without it works
    // in every respect, so failing the command over it blocked deploys on a field that
    // changes nothing. It is still validated when present, and duplicates still warn.
    $plans = config('mollie-billing-plans.plans');
    unset($plans['pro']['tier']);
    config()->set('mollie-billing-plans.plans', $plans);

    $this->artisan('billing:check-config')->assertExitCode(0);
});

it('still errors on a tier that is not an integer', function (): void {
    config()->set('mollie-billing-plans.plans.pro.tier', 'two');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('tier must be an integer')
        ->assertExitCode(1);
});
