<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mpociot\VatCalculator\VatCalculator;

/** VIES stub: accepts the two Austrian numbers this file uses, no network. */
final class VatLockViesCalculator extends VatCalculator
{
    public function __construct()
    {
        parent::__construct([]);
    }

    public function shouldCollectVAT($countryCode): bool
    {
        return true;
    }

    public function getTaxRateForCountry($countryCode, $company = false, $type = null): float
    {
        return 0.20;
    }

    public function getVATDetails($vatNumber)
    {
        return (object) ['valid' => in_array(strtoupper((string) $vatNumber), ['ATU12345678', 'ATU99999999'], true)];
    }
}

/**
 * While a country mismatch is open the billing country is frozen — the dashboard's
 * self-service correction is the only path, because changing it retroactively changes the
 * VAT treatment of invoices already issued (they get refunded and reissued).
 *
 * The VAT number decides exactly the same thing: setting one turns a gross B2C supply into
 * a net reverse-charge one and clearing it does the reverse. Freezing only the country left
 * that half open, so the reissued invoices could be recomputed against a VAT status the
 * correction never reviewed.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    $this->app->instance(VatCalculator::class, new VatLockViesCalculator);

    MollieBilling::authUsing(fn (): bool => true);
});

function mismatchBillable(?string $vatNumber = null, bool $withOpenMismatch = true): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme GmbH', 'email' => 'mismatch@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_street' => 'Acme Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'vat_number' => $vatNumber,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    if ($vatNumber !== null) {
        $validation = new BillingVatValidation;
        $validation->billable_type = $billable->getMorphClass();
        $validation->billable_id = $billable->getKey();
        $validation->vat_number = $vatNumber;
        $validation->country_code = substr($vatNumber, 0, 2);
        $validation->valid = true;
        $validation->vies_response = [];
        $validation->checked_at = now();
        $validation->save();
    }

    if ($withOpenMismatch) {
        BillingCountryMismatch::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'tax_country_user' => 'AT',
            'tax_country_payment' => 'DE',
            'tax_country_ip' => 'DE',
            'status' => CountryMismatchStatus::Pending,
        ]);
    }

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

it('refuses to add a VAT number while a country mismatch is open', function (): void {
    $billable = mismatchBillable();

    Livewire::test('mollie-billing::billing-data')
        ->set('vat_number', 'ATU12345678')
        ->call('save')
        ->assertHasErrors('vat_number');

    expect($billable->refresh()->vat_number)->toBeNull();
});

it('refuses to clear a VAT number while a country mismatch is open', function (): void {
    $billable = mismatchBillable('ATU12345678');

    Livewire::test('mollie-billing::billing-data')
        ->set('vat_number', '')
        ->call('save')
        ->assertHasErrors('vat_number');

    expect($billable->refresh()->vat_number)->toBe('ATU12345678');
});

it('still allows saving the rest of the address while a country mismatch is open', function (): void {
    $billable = mismatchBillable();

    Livewire::test('mollie-billing::billing-data')
        ->set('billing_street', 'New Street 9')
        ->call('save')
        ->assertHasNoErrors();

    expect($billable->refresh()->getBillingStreet())->toBe('New Street 9');
});

it('treats a whitespace-only difference in the VAT number as unchanged', function (): void {
    $billable = mismatchBillable('ATU12345678');

    Livewire::test('mollie-billing::billing-data')
        ->set('vat_number', 'atu 123 456 78')
        ->set('billing_city', 'Graz')
        ->call('save')
        ->assertHasNoErrors();

    expect($billable->refresh()->getBillingCity())->toBe('Graz');
});

it('refuses to swap one VAT number for another while a country mismatch is open', function (): void {
    $billable = mismatchBillable('ATU12345678');

    Livewire::test('mollie-billing::billing-data')
        ->set('vat_number', 'ATU99999999')
        ->call('save')
        ->assertHasErrors('vat_number');

    expect($billable->refresh()->vat_number)->toBe('ATU12345678');
});

it('allows a VAT number change once no mismatch is open', function (): void {
    $billable = mismatchBillable(null, withOpenMismatch: false);

    Livewire::test('mollie-billing::billing-data')
        ->set('vat_number', 'ATU12345678')
        ->call('save')
        ->assertHasNoErrors();

    expect($billable->refresh()->vat_number)->toBe('ATU12345678');
});

it('shows the frozen-VAT hint while a country mismatch is open', function (): void {
    mismatchBillable();

    Livewire::test('mollie-billing::billing-data')
        ->assertSee(__('billing::portal.billing_data.vat_locked_by_mismatch'))
        ->assertDontSee(__('billing::portal.billing_data.vat_number_help'));
});
