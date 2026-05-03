<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Mpociot\VatCalculator\VatCalculator;

/**
 * Stub VAT calculator that avoids any network/VIES calls.
 * Returns hardcoded EU rates and accepts only specific VAT numbers as valid.
 */
final class FakeVatCalculator extends VatCalculator
{
    /** @var array<string, float> */
    private array $rates = [
        'DE' => 0.19,
        'AT' => 0.20,
        'FR' => 0.20,
        'NL' => 0.21,
    ];

    /** @var array<string, bool> */
    public array $validVatNumbers = [
        'ATU12345678' => true,
    ];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function shouldCollectVAT($countryCode): bool
    {
        return isset($this->rates[strtoupper((string) $countryCode)]);
    }

    public function getTaxRateForCountry($countryCode, $company = false, $type = null): float
    {
        return $this->rates[strtoupper((string) $countryCode)] ?? 0.0;
    }

    public function getTaxRateForLocation($countryCode, $postalCode = null, $company = false, $type = null): float
    {
        return $this->rates[strtoupper((string) $countryCode)] ?? 0.0;
    }

    public function isValidVATNumber($vatNumber): bool
    {
        $vatNumber = strtoupper(str_replace([' ', '-'], '', (string) $vatNumber));

        return $this->validVatNumbers[$vatNumber] ?? false;
    }
}

beforeEach(function (): void {
    $this->app->instance(VatCalculator::class, new FakeVatCalculator());
});

it('calculates German B2C VAT at 19% on 1000 cents net', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $result = $service->calculate('DE', 1000);

    expect($result)->toMatchArray([
        'net' => 1000,
        'vat' => 190,
        'gross' => 1190,
        'rate' => 19.0,
    ]);
});

it('applies B2B reverse-charge when the billable has a valid persisted VIES validation', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    // Customer in DE with a persisted, valid VIES audit entry => reverse-charge.
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => 'DE',
        'vat_number' => 'ATU12345678',
    ]);
    $billable->vatValidations()->create([
        'vat_number' => 'ATU12345678',
        'country_code' => 'AT',
        'valid' => true,
        'vies_response' => ['valid' => true],
        'checked_at' => now(),
    ]);

    $result = $service->calculate('DE', 5000, $billable);

    expect($result['net'])->toBe(5000);
    expect($result['vat'])->toBe(0);
    expect($result['gross'])->toBe(5000);
    expect($result['rate'])->toBe(0.0);
});

it('falls back to country VAT when the billable has no current VIES validation', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => 'DE',
        'vat_number' => 'ATU12345678',
    ]);
    // No vatValidations entry => calculate() must charge German VAT.

    $result = $service->calculate('DE', 5000, $billable);

    expect($result['vat'])->toBe(950);
    expect($result['rate'])->toBe(19.0);
});

it('falls back to country VAT when the latest VIES validation says invalid', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => 'DE',
        'vat_number' => 'ATU99999999',
    ]);
    $billable->vatValidations()->create([
        'vat_number' => 'ATU99999999',
        'country_code' => 'AT',
        'valid' => false,
        'vies_response' => ['valid' => false],
        'checked_at' => now(),
    ]);

    $result = $service->calculate('DE', 5000, $billable);

    expect($result['vat'])->toBe(950);
    expect($result['rate'])->toBe(19.0);
});

it('does not call VIES during calculate()', function () {
    // The default test setup uses FakeVatCalculator (above). FakeVatCalculator's
    // isValidVATNumber returns true only for ATU12345678 — anything else is
    // false. If calculate() ever fell back to live VIES validation, an unknown
    // VAT number would yield 0 and we'd misinterpret it as reverse-charge.
    // Instead, persist a contradicting audit entry: VAT-validation says invalid,
    // but the FakeVatCalculator would say valid. calculate() must trust the
    // persisted state, not VIES → so the country rate must apply.
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => 'DE',
        'vat_number' => 'ATU12345678',
    ]);
    $billable->vatValidations()->create([
        'vat_number' => 'ATU12345678',
        'country_code' => 'AT',
        'valid' => false, // contradicts FakeVatCalculator's "valid"
        'vies_response' => ['valid' => false],
        'checked_at' => now(),
    ]);

    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);
    $result = $service->calculate('DE', 1000, $billable);

    // If calculate() called VIES live, it would have got valid=true and applied
    // reverse-charge. The persisted state says invalid, so country rate must apply.
    expect($result['rate'])->toBe(19.0);
    expect($result['vat'])->toBe(190);
});

it('throws NonEuCountryException for a non-EU country with no override entry', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $service->calculate('CH', 1000);
})->throws(NonEuCountryException::class);

it('honors a vat_rate_overrides config entry over the calculator rate', function () {
    config()->set('mollie-billing.vat_rate_overrides.DE', 7.0);

    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $result = $service->calculate('DE', 1000);

    expect($result)->toMatchArray([
        'net' => 1000,
        'vat' => 70,
        'gross' => 1070,
        'rate' => 7.0,
    ]);
});

it('treats a country listed under additional_countries as billable with its rate', function () {
    config()->set('mollie-billing.additional_countries.CH', [
        'vat_rate' => 8.1,
        'name' => 'Switzerland',
    ]);

    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    $result = $service->calculate('CH', 10000);

    expect($result['rate'])->toBe(8.1);
    expect($result['vat'])->toBe(810);
    expect($result['gross'])->toBe(10810);
});
