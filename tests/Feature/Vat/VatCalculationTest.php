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

it('applies B2B reverse-charge for a valid EU VAT number', function () {
    /** @var VatCalculationService $service */
    $service = $this->app->make(VatCalculationService::class);

    // Customer in DE, but holds a valid Austrian VAT number => reverse-charge.
    $result = $service->calculate('DE', 5000, 'ATU12345678');

    expect($result['net'])->toBe(5000);
    expect($result['vat'])->toBe(0);
    expect($result['gross'])->toBe(5000);
    expect($result['rate'])->toBe(0.0);
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
