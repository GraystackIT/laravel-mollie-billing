<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * How many minor units make up one major unit is a property of the currency, so the
 * display sites that fed `BillingMoney::number($cents / 100)` were wrong for every
 * currency that is not written with two decimals: a ¥2900 plan rendered as "29".
 * `numberFromMinor()` reads the scale off the currency instead.
 *
 * These are the sites where the amount goes into a translation placeholder and therefore
 * cannot use `format()` (which already handles the scale internally).
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 290000,
                'seat_price_net' => 50000,
                'included_usages' => ['tokens' => 1000],
                'usage_overage_prices' => ['tokens' => 300],
            ],
            'yearly' => [
                'base_price_net' => 2900000,
                'seat_price_net' => 500000,
                'included_usages' => ['tokens' => 12000],
                'usage_overage_prices' => ['tokens' => 300],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usages.tokens', ['name' => 'Tokens', 'rollover' => false]);

    // JPY has no minor unit at all, so `/ 100` is off by a factor of a hundred.
    config()->set('mollie-billing.currency', 'JPY');
    config()->set('mollie-billing.currency_symbol', '¥');

    MollieBilling::authUsing(fn (): bool => true);
});

it('renders the checkout quota prices at the currency scale', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')
        ->set('step', 2)
        // Seat 50000 and overage 300 with no VAT-free B2B: 20% AT VAT → 60000 and 360.
        ->assertSee('+¥60,000/')
        ->assertSee('+¥360/')
        // What `number($cents / 100)` produced at JPY's zero fraction digits.
        ->assertDontSee('+¥600/')
        ->assertDontSee('+¥4/');
});

it('renders the plan-change quota prices at the currency scale', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'jpy@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['seat_count' => 2, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::plan-change')
        ->assertSee('+¥60,000/')
        ->assertSee('+¥360/')
        ->assertDontSee('+¥600/')
        ->assertDontSee('+¥4/');
});
