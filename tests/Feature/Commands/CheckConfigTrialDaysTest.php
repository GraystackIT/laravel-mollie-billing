<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Testing\TestBillable;

// Mirrors the baseline setup from CheckConfigCommandTest, then adds focused
// scenarios around trial_days validation.
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
                'yearly' => [
                    'base_price_net' => 10000,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 1200],
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
});

it('passes when trial_days is set per interval as a non-negative integer', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 14);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.trial_days', 30);

    $this->artisan('billing:check-config')->assertExitCode(0);
});

it('fails when interval-level trial_days is not an integer', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 'fourteen');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('trial_days must be a non-negative integer')
        ->assertExitCode(1);
});

it('fails when interval-level trial_days is negative', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', -1);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('trial_days must be a non-negative integer')
        ->assertExitCode(1);
});

it('fails when trial_days is set at the plan root', function (): void {
    config()->set('mollie-billing-plans.plans.pro.trial_days', 14);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('trial_days is no longer supported at plan level')
        ->assertExitCode(1);
});

it('warns when trial_days is set on a free interval', function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 0,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => [],
                'usage_overage_prices' => [],
            ],
        ],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('trial flow only applies to paid intervals')
        ->assertExitCode(0);
});
