<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing-plans.plans', [
        'team' => [
            'name' => 'Team', 'tier' => 2, 'included_seats' => 3,
            'feature_keys' => [], 'allowed_addons' => ['crm'],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 5000, 'seat_price_net' => 500,
                    'included_usages' => ['Tokens' => 100], 'usage_overage_prices' => ['Tokens' => 7],
                ],
                'yearly' => [
                    'base_price_net' => 50000, 'seat_price_net' => 5000,
                    'included_usages' => ['Tokens' => 1200], 'usage_overage_prices' => ['Tokens' => 7],
                ],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'crm' => ['name' => 'CRM', 'feature_keys' => [], 'intervals' => [
            'monthly' => ['price_net' => 2000, 'seat_price_net' => 200],
            'yearly' => ['price_net' => 20000, 'seat_price_net' => 2000],
        ]],
    ]);
    MollieBilling::authUsing(fn (): bool => true);
    $vat = \Mockery::mock(VatCalculationService::class, [app(\Mpociot\VatCalculator\VatCalculator::class)])->makePartial();
    $vat->shouldReceive('validateVatNumber')->andReturn(true);
    app()->instance(VatCalculationService::class, $vat);
});

function domesticPriceBillable(?string $vatNumber = 'ATU12345678', string $country = 'AT'): TestBillable
{
    $billable = TestBillable::create(['name' => 'Business', 'email' => 'business@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_street' => 'Street 1', 'billing_postal_code' => '1010', 'billing_city' => 'Vienna',
        'billing_country' => $country, 'vat_number' => $vatNumber,
        'active_addon_codes' => ['crm'],
        'mollie_customer_id' => 'cst_business', 'mollie_mandate_id' => 'mdt_business',
        'subscription_meta' => ['seat_count' => 5, 'used_seats' => 1, 'mollie_subscription_id' => 'sub_business'],
    ])->save();

    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

it('shows net prices first with individual VAT labels for plans, seats and usage', function (string $interval, int $factor): void {
    domesticPriceBillable();

    Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', $interval)
        ->assertSeeHtmlInOrder([BillingMoney::format(5000 * $factor), 'excl. VAT', BillingMoney::format(6000 * $factor), 'incl. VAT'])
        ->assertDontSee(__('billing::portal.prices_incl_vat'))
        ->assertSee(BillingMoney::format(500 * $factor))
        ->assertSee(BillingMoney::format(7));
})->with(['monthly' => ['monthly', 1], 'yearly' => ['yearly', 10]]);

it('shows gross and net addon, addon-seat and effective seat prices', function (): void {
    domesticPriceBillable();

    Livewire::test('mollie-billing::addons')
        ->assertSeeHtmlInOrder([BillingMoney::format(2000), 'excl. VAT', BillingMoney::format(2400), 'incl. VAT'])
        ->assertSee(BillingMoney::format(240))
        ->assertSee(BillingMoney::format(200));

    Livewire::test('mollie-billing::seats')
        ->assertSeeHtmlInOrder([BillingMoney::format(500), 'excl. VAT', BillingMoney::format(600), 'incl. VAT'])
        ->assertSee(BillingMoney::format(840))
        ->assertSee(BillingMoney::format(700));
});

it('shows both prices on single and multiple plan checkout cards', function (bool $multiplePlans): void {
    MollieBilling::resolveBillableUsing(fn () => null);
    if ($multiplePlans) {
        config()->set('mollie-billing-plans.plans.second', array_replace(config('mollie-billing-plans.plans.team'), ['name' => 'Second']));
    }

    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')->set('vat_number', 'ATU12345678')->set('step', 2)
        ->assertSeeHtmlInOrder([BillingMoney::format(5000), 'excl. VAT', BillingMoney::format(6000), 'incl. VAT'])
        ->assertSee(BillingMoney::format(500))
        ->assertSee(BillingMoney::format(7))
        ->assertSee(BillingMoney::format(2000))
        ->assertSee(BillingMoney::format(200));

    $component->set('plan_code', 'team');
    expect($component->instance()->totals(app(VatCalculationService::class))['gross'])->toBe(6000);
})->with([false, true]);

it('shows both prices on checkout addon and seat selection', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);
    Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')->set('vat_number', 'ATU12345678')->set('plan_code', 'team')->set('step', 3)
        ->assertSeeHtmlInOrder([BillingMoney::format(2000), 'excl. VAT', BillingMoney::format(2400), 'incl. VAT'])
        ->assertSee(BillingMoney::format(200))
        ->assertSee(BillingMoney::format(500));
});

it('uses persisted billing data for both prices in a locked checkout', function (): void {
    $billable = domesticPriceBillable();
    $billable->forceFill(['subscription_status' => SubscriptionStatus::Cancelled])->save();

    Livewire::test('mollie-billing::checkout')
        ->assertSet('billing_locked', true)
        ->set('vat_number', null)
        ->set('billing_country', 'DE')
        ->set('step', 2)
        ->assertSeeHtmlInOrder([BillingMoney::format(5000), 'excl. VAT', BillingMoney::format(6000), 'incl. VAT']);
});

it('keeps gross-only prices for consumers', function (string $screen): void {
    domesticPriceBillable(null);

    Livewire::test('mollie-billing::'.$screen)
        ->assertDontSee(__('billing::portal.price_excl_vat'));
})->with(['plan-change', 'addons', 'seats', 'checkout']);

it('keeps net-only prices for reverse-charge customers', function (): void {
    $billable = domesticPriceBillable('DE123456789', 'DE');
    $validation = new BillingVatValidation;
    $validation->forceFill([
        'billable_type' => $billable->getMorphClass(), 'billable_id' => $billable->getKey(),
        'vat_number' => 'DE123456789', 'country_code' => 'DE', 'valid' => true,
        'vies_response' => [], 'checked_at' => now(),
    ])->save();

    Livewire::test('mollie-billing::plan-change')
        ->assertSee(BillingMoney::format(5000))
        ->assertSee(__('billing::portal.prices_excl_vat'))
        ->assertDontSee(BillingMoney::format(5950))
        ->assertDontSee(__('billing::portal.price_incl_vat'));
});

it('localizes the net breakdown and uses the configured currency', function (): void {
    domesticPriceBillable();
    app()->setLocale('de');
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);

    Livewire::test('mollie-billing::plan-change')
        ->assertSeeHtmlInOrder([BillingMoney::format(5000), 'exkl. USt.', BillingMoney::format(6000), 'inkl. USt.'])
        ->assertDontSee('Preise inkl. USt.')
        ->assertDontSee('Brutto')
        ->assertDontSee('Netto');
});


it('keeps discounted seat totals consistent between net and VAT-inclusive display', function (): void {
    domesticPriceBillable();

    Livewire::test('mollie-billing::seats')
        ->set('couponDiscountNet', 200)
        ->assertSeeHtmlInOrder([BillingMoney::format(800), 'excl. VAT', BillingMoney::format(960), 'incl. VAT']);
});
