<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * `billing:check-config` against `products.{code}.show_unit_price`: only a literal
 * boolean is read (a string from env() would silently show nothing), and a `true` on a
 * product the page will never price per unit is named, because nothing else says why the
 * line is missing.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');

    config()->set('mollie-billing-plans.features', ['f' => ['name' => 'F']]);
    config()->set('mollie-billing-plans.usage_types', ['SMS' => ['rollover' => false]]);
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => ['f'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'included_usages' => ['SMS' => 10], 'usage_overage_prices' => ['SMS' => 9]],
                'yearly' => ['base_price_net' => 29000, 'included_usages' => ['SMS' => 120], 'usage_overage_prices' => ['SMS' => 8]],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', []);
    config()->set('mollie-billing-plans.products', [
        'sms-1000' => ['name' => 'SMS 1000', 'price_net' => 8330, 'usage_type' => 'SMS', 'quantity' => 1000, 'show_unit_price' => true],
    ]);
});

it('accepts a pack with a usage type and a quantity above 1', function (): void {
    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('show_unit_price')
        ->assertExitCode(0);
});

it('accepts an explicit false anywhere', function (): void {
    config()->set('mollie-billing-plans.products.consulting', ['name' => 'Consulting', 'price_net' => 5000, 'show_unit_price' => false]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('show_unit_price')
        ->assertExitCode(0);
});

it('errors on a show_unit_price that is not a boolean', function (mixed $value): void {
    config()->set('mollie-billing-plans.products.sms-1000.show_unit_price', $value);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('products.sms-1000.show_unit_price must be a boolean')
        ->assertExitCode(1);
})->with([
    'string true' => ['true'],
    'integer 1' => [1],
    'string 1' => ['1'],
]);

it('warns when show_unit_price is true but no unit price can be shown', function (array $product): void {
    config()->set('mollie-billing-plans.products.odd', $product + ['name' => 'Odd', 'price_net' => 1000, 'show_unit_price' => true]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('products.odd.show_unit_price is true, but the product has no usage_type or a quantity of 1 or less')
        ->assertExitCode(0);
})->with([
    'no usage type' => [[]],
    'quantity of 1' => [['usage_type' => 'SMS', 'quantity' => 1]],
    'usage type without quantity' => [['usage_type' => 'SMS']],
]);
