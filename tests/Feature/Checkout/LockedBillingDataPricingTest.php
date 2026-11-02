<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * `billing_locked` is set when the checkout is entered with a billable that already
 * carries persisted billing data: the address is rendered read-only and `submit()`
 * deliberately never writes it back. `billing_country` and `vat_number` stay
 * `wire:model`-bound for the unlocked case though, and `totals()['gross']` is handed to
 * Mollie verbatim as `amount_gross` — so while locked, the price has to come from the
 * persisted record, not from whatever the browser sends back.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    MollieBilling::authUsing(fn (): bool => true);
});

/** A returning customer with persisted billing data and no accessible subscription. */
function lockedCheckoutBillable(?string $vatNumber = null, string $country = 'AT'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme GmbH', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Acme Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => $country,
        'tax_country_user' => $country,
        'vat_number' => $vatNumber,
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** VIES audit row marking `$vatNumber` valid for `$billable`. */
function lockedCheckoutVatValidation(TestBillable $billable, string $vatNumber, string $countryCode): void
{
    $validation = new BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = $vatNumber;
    $validation->country_code = $countryCode;
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();
}

it('locks the checkout onto the persisted country', function (): void {
    lockedCheckoutBillable();

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    expect($component->instance()->billing_locked)->toBeTrue();

    // Luxembourg charges 17%, Austria 20% — the persisted country must win.
    $component->set('billing_country', 'LU');

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($totals['rate'])->toBe(20.0)
        ->and($totals['vat'])->toBe(600)
        ->and($totals['gross'])->toBe(3600);
});

it('does not let a locked checkout claim reverse charge with a VAT number that was never persisted', function (): void {
    // German record, Austrian seller — cross-border, so the country half of the
    // reverse-charge test passes and only the VAT number decides. Nothing is on file.
    lockedCheckoutBillable(null, 'DE');

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    // The realistic attack: a genuinely VIES-valid number belonging to somebody else, so
    // `updatedVatNumber()` confirms it. Both are written server-side because the test
    // environment cannot reach VIES; `vatNumberValid` is #[Locked] either way.
    $component->instance()->vat_number = 'DE123456789';
    $component->instance()->vatNumberValid = true;

    expect($component->instance()->showsNetPrices())->toBeFalse();

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($totals['reverseCharge'])->toBeFalse()
        ->and($totals['gross'])->toBe(3570);
});

it('still prices an unlocked checkout from the form', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('billing_country', 'LU');

    expect($component->instance()->billing_locked)->toBeFalse();

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($totals['rate'])->toBe(17.0)
        ->and($totals['gross'])->toBe(3510);
});

it('keeps reverse charge for a locked checkout whose persisted VAT number VIES confirmed', function (): void {
    $billable = lockedCheckoutBillable('DE123456789', 'DE');
    lockedCheckoutVatValidation($billable, 'DE123456789', 'DE');

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    expect($component->instance()->showsNetPrices())->toBeTrue()
        ->and($component->instance()->totals(app(VatCalculationService::class))['gross'])->toBe(3000);
});

/**
 * The seller-country policy lives in VatCalculationService and must not be reimplemented
 * here. A configured-but-malformed `vat.seller_country` is a third state: the service
 * refuses reverse charge for it regardless of `require_seller_country`, because the
 * alternative is reverse-charging every domestic B2B supply at 0% and paying that VAT
 * out of pocket. The checkout has to render and collect the same verdict.
 */
it('shows gross when the seller country is configured but malformed', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AUT');
    config()->set('mollie-billing.vat.require_seller_country', false);
    config()->set('mollie-billing.invoices.seller.address.country', null);

    // Persisted German VAT number but no audit row yet, so mount() leaves the flag alone
    // and the seller-country branch is the only thing left to decide.
    lockedCheckoutBillable('DE123456789', 'DE');

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    // The state validateVatNumberLive() leaves behind when a customer types a VIES-valid
    // number during checkout.
    $component->instance()->vatNumberValid = true;

    expect($component->instance()->showsNetPrices())->toBeFalse();

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($totals['reverseCharge'])->toBeFalse()
        ->and($totals['gross'])->toBe(3570);
});

it('still grants reverse charge when no seller country is configured at all', function (): void {
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.vat.require_seller_country', false);
    config()->set('mollie-billing.invoices.seller.address.country', null);

    lockedCheckoutBillable('DE123456789', 'DE');

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    $component->instance()->vatNumberValid = true;

    expect($component->instance()->showsNetPrices())->toBeTrue()
        ->and($component->instance()->totals(app(VatCalculationService::class))['gross'])->toBe(3000);
});

it('does not let a VIES-confirmed form number lend reverse charge to a different persisted one', function (): void {
    // On file: a German VAT number VIES never confirmed (no audit row). The locked form
    // keeps `vat_number` live-bound, so a customer could type someone else's valid number,
    // have `updatedVatNumber()` confirm *that* one — and `showsNetPrices()` combined the
    // flag with the persisted number, granting net pricing for a number nobody verified.
    // The invoice is written from the persisted record, so the webhook then booked the
    // missing VAT as an adjustment.
    lockedCheckoutBillable('DE111111111', 'DE');

    $component = Livewire::test('mollie-billing::checkout')->set('plan_code', 'pro');

    $component->instance()->vat_number = 'DE123456789';
    $component->instance()->vatNumberValid = true;

    expect($component->instance()->showsNetPrices())->toBeFalse()
        ->and($component->instance()->totals(app(VatCalculationService::class))['gross'])->toBe(3570);
});
