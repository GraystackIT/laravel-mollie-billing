<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    // Establish a known-good baseline for both config files.
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
        'drivers' => [
            'null' => [],
        ],
    ]);
    config()->set('mollie-billing.billing_timezone', 'UTC');
    config()->set('mollie-billing.overage_job_time', '02:00');
    config()->set('mollie-billing.usage_threshold_percent', 80);

    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'trial_days' => 0,
            'included_seats' => 1,
            'feature_keys' => ['dashboard'],
            'allowed_addons' => ['softdrinks'],
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
        'softdrinks' => ['name' => 'Softdrinks', 'description' => null],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'softdrinks' => [
            'name' => 'Softdrinks',
            'feature_keys' => ['softdrinks'],
            'intervals' => [
                'monthly' => ['price_net' => 490],
                'yearly' => ['price_net' => 4900],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.product_groups', []);
    config()->set('mollie-billing-plans.products', []);
});

it('passes on a valid configuration', function (): void {
    $this->artisan('billing:check-config')
        ->expectsOutputToContain('Billing configuration is valid')
        ->assertExitCode(0);
});

it('fails when billable_model is missing', function (): void {
    config()->set('mollie-billing.billable_model', null);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('billable_model is not set')
        ->assertExitCode(1);
});

it('fails when billable_model class does not exist', function (): void {
    config()->set('mollie-billing.billable_model', 'App\\Models\\DoesNotExist');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(1);
});

it('fails when billable_key_type is invalid', function (): void {
    config()->set('mollie-billing.billable_key_type', 'snowflake');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('billable_key_type')
        ->assertExitCode(1);
});

it('fails when invoices.disk is not configured', function (): void {
    config()->set('mollie-billing.invoices.disk', 'does-not-exist');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('invoices.disk [does-not-exist]')
        ->assertExitCode(1);
});

it('fails when serial_number.format has no counter slot', function (): void {
    config()->set('mollie-billing.invoices.serial_number.format', 'PP-YY');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain("must contain at least one 'C'")
        ->assertExitCode(1);
});

it('fails when ip_geolocation driver is not declared in drivers', function (): void {
    config()->set('mollie-billing.ip_geolocation', [
        'driver' => 'maxmind',
        'drivers' => ['null' => []],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('ip_geolocation.driver [maxmind]')
        ->assertExitCode(1);
});

it('warns when db_ip driver is selected without an api key', function (): void {
    config()->set('mollie-billing.ip_geolocation', [
        'driver' => 'db_ip',
        'drivers' => ['db_ip' => ['api_key' => null]],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('ip_geolocation.drivers.db_ip.api_key is empty')
        ->assertExitCode(0);
});

it('fails when checkout_countries.include contains invalid ISO codes', function (): void {
    config()->set('mollie-billing.checkout_countries.include', ['ch']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('checkout_countries.include')
        ->assertExitCode(1);
});

it('fails when billing_timezone is not a valid IANA identifier', function (): void {
    config()->set('mollie-billing.billing_timezone', 'Mars/Olympus_Mons');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('billing_timezone')
        ->assertExitCode(1);
});

it('fails when overage_job_time is not HH:MM', function (): void {
    config()->set('mollie-billing.overage_job_time', '25:00');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('overage_job_time')
        ->assertExitCode(1);
});

it('fails when plans is empty', function (): void {
    config()->set('mollie-billing-plans.plans', []);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('plans is empty')
        ->assertExitCode(1);
});

it('fails when plan references unknown feature_key', function (): void {
    config()->set('mollie-billing-plans.plans.pro.feature_keys', ['ghost-feature']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('feature_keys references unknown feature [ghost-feature]')
        ->assertExitCode(1);
});

it('fails when plan references unknown allowed_addon', function (): void {
    config()->set('mollie-billing-plans.plans.pro.allowed_addons', ['ghost-addon']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('allowed_addons references unknown addon [ghost-addon]')
        ->assertExitCode(1);
});

it('fails when addon references unknown feature_key', function (): void {
    config()->set('mollie-billing-plans.addons.softdrinks.feature_keys', ['ghost']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('feature_keys references unknown feature [ghost]')
        ->assertExitCode(1);
});

it('fails when plan tier is missing or non-integer', function (): void {
    config()->set('mollie-billing-plans.plans.pro.tier', 'two');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('tier must be an integer')
        ->assertExitCode(1);
});

it('fails when plan interval base_price_net is negative', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.base_price_net', -1);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('base_price_net must be a non-negative integer')
        ->assertExitCode(1);
});

it('warns when included_usages defines a quota without an overage price', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices', []);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('overages cannot be charged')
        ->assertExitCode(0);
});

it('fails when product references unknown product group', function (): void {
    config()->set('mollie-billing-plans.products', [
        'pack' => [
            'name' => 'Pack',
            'price_net' => 1000,
            'group' => 'ghost-group',
        ],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('group references unknown product group')
        ->assertExitCode(1);
});

it('warns when product usage_type does not match any plan usage type', function (): void {
    config()->set('mollie-billing-plans.products', [
        'pack' => [
            'name' => 'Pack',
            'price_net' => 1000,
            'usage_type' => 'NotAType',
            'quantity' => 100,
        ],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('is not declared in any plan')
        ->assertExitCode(0);
});

it('warns when feature is defined but never referenced', function (): void {
    config()->set('mollie-billing-plans.features.orphan', ['name' => 'Orphan']);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('features.orphan is defined but not referenced')
        ->assertExitCode(0);
});

it('errors on invalid ip_block.mode', function (): void {
    config()->set('mollie-billing.ip_block', [
        'enabled' => true,
        'mode' => 'something',
        'countries' => ['AT'],
        'block_unknown' => false,
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain("ip_block.mode [something] must be 'blocklist' or 'allowlist'.")
        ->assertExitCode(1);
});

it('errors on non-ISO ip_block.countries entries', function (): void {
    config()->set('mollie-billing.ip_block', [
        'enabled' => true,
        'mode' => 'blocklist',
        'countries' => ['ru'],
        'block_unknown' => false,
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('ip_block.countries entries must be uppercase ISO-3166-1 alpha-2 codes')
        ->assertExitCode(1);
});

it('errors when allowlist mode has no countries', function (): void {
    config()->set('mollie-billing.ip_block', [
        'enabled' => true,
        'mode' => 'allowlist',
        'countries' => [],
        'block_unknown' => false,
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('every visitor will be blocked')
        ->assertExitCode(1);
});

it('warns when ip_block is enabled but the geolocation driver is null', function (): void {
    config()->set('mollie-billing.ip_block', [
        'enabled' => true,
        'mode' => 'blocklist',
        'countries' => ['RU'],
        'block_unknown' => false,
    ]);
    // The baseline already sets ip_geolocation.driver = 'null'.

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('ip_geolocation.driver is "null"')
        ->assertExitCode(0);
});
