<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

function validPlanConfig(array $planOverrides = []): array
{
    return array_merge([
        'name' => 'Pro',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['tokens' => 100],
                'usage_overage_prices' => ['tokens' => 1],
            ],
        ],
    ], $planOverrides);
}

it('errors on plan-level included_usages, which the catalog never reads', function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => validPlanConfig(['included_usages' => ['tokens' => 5000]]),
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('included_usages is not read at plan level')
        ->assertFailed();
});

it('errors on plan-level usage_overage_prices', function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => validPlanConfig(['usage_overage_prices' => ['tokens' => 2]]),
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('usage_overage_prices is not read at plan level')
        ->assertFailed();
});

it('accepts the interval-scoped shape', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('is not read at plan level');
});

it('rejects an out-of-range trial_lifecycle_job_time', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);
    // `0 25 * * *` makes schedule:run throw for the entire application's schedule.
    config()->set('mollie-billing.trial_lifecycle_job_time', '25:00');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('trial_lifecycle_job_time')
        ->assertFailed();
});

it('rejects an invalid cleanup cron expression', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);
    config()->set('mollie-billing.cleanup.cron_expression', 'every 15 minutes please');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('cron_expression')
        ->assertFailed();
});

it('accepts a valid cron expression', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);
    config()->set('mollie-billing.cleanup.cron_expression', '*/15 * * * *');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('cron_expression');
});

it('warns when currency_symbol contradicts currency', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);
    config()->set('mollie-billing.currency', 'USD');
    config()->set('mollie-billing.currency_symbol', '€');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('does not match currency');
});

it('does not warn when the symbol is left to be derived', function (): void {
    config()->set('mollie-billing-plans.plans', ['pro' => validPlanConfig()]);
    config()->set('mollie-billing.currency', 'USD');
    config()->set('mollie-billing.currency_symbol', null);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('does not match currency');
});
