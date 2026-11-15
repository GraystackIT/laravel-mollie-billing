<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * `billing:check-config` against the fields that came with per-seat addons, hidden
 * features and one-time products: every shape that would bill wrongly — or sell
 * something that then cannot be bought — has to be named here, because nothing fails at
 * boot.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');

    config()->set('mollie-billing-plans.features', ['f' => ['name' => 'F']]);
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => ['f'], 'allowed_addons' => ['crm'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500],
                'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 5000],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'crm' => [
            'name' => 'CRM', 'feature_keys' => ['f'], 'included_seats' => 1,
            'intervals' => [
                'monthly' => ['price_net' => 1000, 'seat_price_net' => 300],
                'yearly' => ['price_net' => 10000, 'seat_price_net' => 3000],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.products', ['pack' => ['name' => 'Pack', 'price_net' => 1000]]);
});

it('accepts the baseline', function (): void {
    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('addons.crm')
        ->doesntExpectOutputToContain('products.pack')
        ->assertExitCode(0);
});

it('errors on a per-seat addon field that would bill wrongly', function (string $key, mixed $value, string $needle): void {
    config()->set('mollie-billing-plans.'.$key, $value);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain($needle)
        ->assertExitCode(1);
})->with([
    'seat price as a string' => ['addons.crm.intervals.monthly.seat_price_net', '300', 'addons.crm.intervals.monthly.seat_price_net'],
    'seat price as a decimal' => ['addons.crm.intervals.monthly.seat_price_net', 3.0, 'addons.crm.intervals.monthly.seat_price_net'],
    'negative seat price' => ['addons.crm.intervals.monthly.seat_price_net', -300, 'addons.crm.intervals.monthly.seat_price_net'],
    'seat price with no base price' => ['addons.crm.intervals.monthly', ['seat_price_net' => 300], 'addons.crm.intervals.monthly.price_net'],
    'included seats as a string' => ['addons.crm.included_seats', '1', 'addons.crm.included_seats'],
    'negative included seats' => ['addons.crm.included_seats', -1, 'addons.crm.included_seats'],
    'plan included seats as a string' => ['plans.pro.included_seats', '2', 'plans.pro.included_seats'],
    'plan included seats of zero' => ['plans.pro.included_seats', 0, 'plans.pro.included_seats'],
    'plan seat price as a string' => ['plans.pro.intervals.monthly.seat_price_net', '500', 'plans.pro.intervals.monthly.seat_price_net'],
    'feature visibility as a string' => ['features.f.visible', 'false', 'features.f.visible'],
    'feature visibility as an integer' => ['features.f.visible', 0, 'features.f.visible'],
    // StartOneTimeOrderCheckout refuses a product without a price, so offering one is
    // a card whose button always fails.
    'product price of zero' => ['products.pack.price_net', 0, 'products.pack.price_net'],
    'product price as a string' => ['products.pack.price_net', '1000', 'products.pack.price_net'],
]);

it('warns about a per-seat addon whose seat price can never apply or applies on one interval only', function (Closure $arrange, string $needle): void {
    $arrange();

    $this->artisan('billing:check-config')
        ->expectsOutputToContain($needle)
        ->assertExitCode(0);
})->with([
    // Sold per seat monthly, flat yearly: a customer switching to yearly stops paying
    // for seats, and nothing says that was meant.
    'a seat price on one interval only' => [
        fn () => config()->set('mollie-billing-plans.addons.crm.intervals.yearly', ['price_net' => 10000]),
        'addons.crm declares seat_price_net on [monthly] but not on [yearly]',
    ],
    // Free seats with nothing to be free of.
    'included seats without any seat price' => [
        fn () => config()->set('mollie-billing-plans.addons.crm.intervals', ['monthly' => ['price_net' => 1000], 'yearly' => ['price_net' => 10000]]),
        'addons.crm.included_seats is set but no interval has a seat_price_net',
    ],
    // The plan sells no extra seats and includes no more than the addon gives away, so
    // on this plan the per-seat price is never charged.
    'a plan that can never reach the charged seats' => [
        function (): void {
            config()->set('mollie-billing-plans.plans.pro.included_seats', 1);
            config()->set('mollie-billing-plans.plans.pro.intervals.monthly.seat_price_net', null);
            config()->set('mollie-billing-plans.plans.pro.intervals.yearly.seat_price_net', null);
        },
        'plans.pro allows the per-seat addon [crm]',
    ],
]);

it('says nothing about a per-seat addon on a plan without extra seats when the included seats do reach it', function (): void {
    // 2 included plan seats, the addon gives 1 free: one seat is charged, always.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.seat_price_net', null);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.seat_price_net', null);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('per-seat addon')
        ->assertExitCode(0);
});
