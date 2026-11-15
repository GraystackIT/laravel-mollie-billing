<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * DEV-380 on the screens: an addon with a per-seat price shows both prices, the seat
 * screen shows what a seat really costs with the active addons, and the checkout — which
 * assembles its own total — arrives at the number SubscriptionAmount charges.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 3,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'team' => [
            'name' => 'Team', 'tier' => 2, 'included_seats' => 10,
            'feature_keys' => [], 'allowed_addons' => ['crm', 'sso'],
            'intervals' => [
                'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'crm' => ['name' => 'CRM', 'feature_keys' => [], 'intervals' => [
            'monthly' => ['price_net' => 2000, 'seat_price_net' => 500],
            'yearly' => ['price_net' => 20000, 'seat_price_net' => 5000],
        ]],
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'included_seats' => 5, 'intervals' => [
            'monthly' => ['price_net' => 0, 'seat_price_net' => 200],
            'yearly' => ['price_net' => 0, 'seat_price_net' => 2000],
        ]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function addonSeatScreenBillable(string $plan, int $seats, array $addons, SubscriptionSource $source = SubscriptionSource::Mollie): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Screens Co', 'email' => uniqid('addon-seat-screen-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => $source,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => $source === SubscriptionSource::Mollie ? 'cst_screen' : null,
        'mollie_mandate_id' => $source === SubscriptionSource::Mollie ? 'mdt_screen' : null,
        'active_addon_codes' => $addons,
        'subscription_meta' => array_filter([
            'seat_count' => $seats,
            'used_seats' => 1,
            'mollie_subscription_id' => $source === SubscriptionSource::Mollie ? 'sub_screen' : null,
        ]),
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

it('shows base and per-seat price on the addons screen', function (): void {
    addonSeatScreenBillable('team', 12, []);

    // Gross for a B2C customer in AT: 20.00 → 24.00, 5.00 → 6.00, 2.00 → 2.40.
    Livewire::test('mollie-billing::addons')
        ->assertSee(BillingMoney::format(2400))
        ->assertSee(__('billing::portal.addon_price_per_seat', ['price' => BillingMoney::format(600)]))
        ->assertSee(__('billing::portal.addon_price_per_seat', ['price' => BillingMoney::format(240)]))
        ->assertSee(trans_choice('billing::portal.addon_included_seats', 5, ['count' => 5]));
});

it('shows what a seat costs including the active per-seat addons on the seats screen', function (): void {
    addonSeatScreenBillable('team', 12, ['crm', 'sso']);

    // 5.00 + 5.00 + 2.00 = 12.00 net → 14.40 gross.
    Livewire::test('mollie-billing::seats')
        ->assertSee(__('billing::portal.seat_price_with_addons', ['price' => BillingMoney::format(1440)]));
});

it('does not show an effective seat price when no active addon is priced per seat', function (): void {
    addonSeatScreenBillable('team', 12, []);

    Livewire::test('mollie-billing::seats')
        ->assertDontSee(__('billing::portal.seat_price_with_addons', ['price' => BillingMoney::format(600)]));
});

it('assembles the checkout total with the addon seats, as SubscriptionAmount prices it', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')
        ->set('plan_code', 'team')
        ->set('extra_seats', 2)
        ->set('addon_codes', ['crm', 'sso']);

    $instance = $component->instance();
    $totals = $instance->totals(app(VatCalculationService::class));

    $catalog = app(SubscriptionCatalogInterface::class);
    $expectedNet = SubscriptionAmount::net($catalog, new TestBillable, 'team', 'monthly', 12, ['crm', 'sso']);

    // 5000 + 1000 + 2000 + 6000 + 0 + 1400; the base-0 addon's line carries no VAT.
    expect($expectedNet)->toBe(15400)
        ->and($instance->subtotalNet())->toBe(15400)
        ->and($instance->lineNets())->toBe([5000, 1000, 2000, 6000, 0, 1400])
        ->and($totals['net'])->toBe(15400)
        ->and($totals['gross'])->toBe(18480);
});

it('shows the per-seat price on the checkout\'s addon step', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')
        ->set('plan_code', 'team')
        ->set('step', 2)
        ->assertSee('CRM')
        ->assertSeeHtml('/ seat');
});
