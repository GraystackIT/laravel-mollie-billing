<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Testing\TestBillable;

// Same baseline as CheckConfigCommandTest so the configuration is valid
// before each scenario flips a single key to exercise the new validator.
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

it('fails when legacy mollie-billing.usage_rollover key is present', function (): void {
    config()->set('mollie-billing.usage_rollover', true);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('Legacy key `mollie-billing.usage_rollover` is set')
        ->assertExitCode(1);
});

it('fails when legacy plans.<code>.usage_rollover key is present', function (): void {
    config()->set('mollie-billing-plans.plans.pro.usage_rollover', true);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_rollover is no longer supported')
        ->assertExitCode(1);
});

it('fails when legacy BILLING_USAGE_ROLLOVER env var is present', function (): void {
    $_ENV['BILLING_USAGE_ROLLOVER'] = '1';

    try {
        $this->artisan('billing:check-config')
            ->expectsOutputToContain('Legacy env var `BILLING_USAGE_ROLLOVER` is set')
            ->assertExitCode(1);
    } finally {
        unset($_ENV['BILLING_USAGE_ROLLOVER']);
    }
});

it('fails when usage_types entry has no rollover key', function (): void {
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['something_else' => true],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_types.Tokens.rollover is required')
        ->assertExitCode(1);
});

it('fails when usage_types rollover value is not boolean', function (): void {
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => 'yes'],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_types.Tokens.rollover must be a boolean')
        ->assertExitCode(1);
});

it('fails when usage_types entry is not an array', function (): void {
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => true,
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_types.Tokens must be an array')
        ->assertExitCode(1);
});

it('passes with a valid usage_types block', function (): void {
    config()->set('mollie-billing-plans.usage_types', [
        'Tokens' => ['rollover' => true],
    ]);

    $this->artisan('billing:check-config')->assertExitCode(0);
});
