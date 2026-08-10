<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The checkout writes billing data, a VIES audit row and a Mollie payment onto the
 * billable identified by ($billableClass, $billableId) — and prices the payment from
 * $vatNumberValid. All of those are component state, so the browser must not be able
 * to set them, and a billable the component did not create must be authorized.
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

    MollieBilling::authUsing(fn (): bool => true);
});

function checkoutVictim(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Victim Ltd', 'email' => 'victim@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Victim Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ])->save();

    return $billable->refresh();
}

// ── (1) Cross-tenant write ────────────────────────────────────────────────────

it('refuses to let the client repoint the checkout at another billable', function (): void {
    $victim = checkoutVictim();
    MollieBilling::resolveBillableUsing(fn () => null);

    $component = Livewire::test('mollie-billing::checkout');

    expect(fn () => $component->set('billableId', (string) $victim->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('billableClass', TestBillable::class))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to let the client unlock the billing-address step', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    expect(fn () => Livewire::test('mollie-billing::checkout')->set('billing_locked', false))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to let the client claim it created the billable itself', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    expect(fn () => Livewire::test('mollie-billing::checkout')->set('billableCreatedHere', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('forbids submitting for a pre-existing billable when authorization is denied', function (): void {
    $victim = checkoutVictim();
    MollieBilling::resolveBillableUsing(fn () => $victim);
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::checkout')
        // mount() adopted the billable, so the address fields are already filled.
        ->set('plan_code', 'pro')
        ->call('submit')
        ->assertForbidden();

    // Nothing was written to the victim.
    expect($victim->refresh()->getBillingStreet())->toBe('Victim Street 1');
});

it('never writes the billable address before the authorization gate runs', function (): void {
    $victim = checkoutVictim();
    MollieBilling::resolveBillableUsing(fn () => $victim);
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'Attacker GmbH')
        ->set('plan_code', 'pro')
        ->call('submit')
        ->assertForbidden();

    expect($victim->refresh()->getBillingName())->toBe('Victim Ltd');
});

// ── (2) VAT stripped to zero ──────────────────────────────────────────────────

it('refuses to let the client declare a VAT number VIES-valid', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    expect(fn () => Livewire::test('mollie-billing::checkout')->set('vatNumberValid', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('charges gross when the VAT number is empty even if the validity flag says otherwise', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')
        ->set('plan_code', 'pro');

    // Simulate a stale/forged flag by writing it server-side, which is the only way
    // it can be set at all now — the price must still carry VAT because the number
    // itself is empty.
    $component->instance()->vatNumberValid = true;
    $component->instance()->vat_number = null;

    expect($component->instance()->showsNetPrices())->toBeFalse();

    $totals = $component->instance()->totals(app(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class));

    expect($totals['reverseCharge'])->toBeFalse()
        ->and($totals['vat'])->toBe(600)
        ->and($totals['gross'])->toBe(3600);
});

it('only pre-confirms a persisted VAT number when the audit trail says it is valid', function (): void {
    $billable = checkoutVictim();
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();

    $validation = new BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'ATU12345678';
    $validation->country_code = 'AT';
    $validation->valid = false;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $component = Livewire::test('mollie-billing::checkout');

    expect($component->instance()->vatNumberValid)->toBeNull()
        ->and($component->instance()->showsNetPrices())->toBeFalse();
});

it('pre-confirms a persisted VAT number that VIES accepted', function (): void {
    $billable = checkoutVictim();
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();

    $validation = new BillingVatValidation;
    $validation->billable_type = $billable->getMorphClass();
    $validation->billable_id = $billable->getKey();
    $validation->vat_number = 'ATU12345678';
    $validation->country_code = 'AT';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $component = Livewire::test('mollie-billing::checkout');

    expect($component->instance()->vatNumberValid)->toBeTrue()
        ->and($component->instance()->showsNetPrices())->toBeTrue();
});
