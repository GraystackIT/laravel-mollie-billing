<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mpociot\VatCalculator\VatCalculator;

/**
 * The label and the amount used to be decided independently: HasBilling and
 * PreviewService each looked at "is there a valid VIES row", while the charge
 * also compares the seller country. A domestic B2B customer therefore saw net
 * prices and a "Reverse charge" badge and was then billed gross.
 */
final class DisplayReverseChargeCalculator extends VatCalculator
{
    /** @var array<string, float> */
    private array $rates = ['AT' => 0.20, 'DE' => 0.19];

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
        return $this->getTaxRateForCountry($countryCode);
    }
}

beforeEach(function (): void {
    $this->app->instance(VatCalculator::class, new DisplayReverseChargeCalculator());
    config()->set('mollie-billing.vat.seller_country', 'AT');
});

function displayBillable(string $billingCountry, string $vatNumber): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => $billingCountry,
        'vat_number' => $vatNumber,
    ]);

    $billable->vatValidations()->create([
        'vat_number' => $vatNumber,
        'country_code' => $billingCountry,
        'valid' => true,
        'vies_response' => ['valid' => true],
        'checked_at' => now(),
    ]);

    return $billable;
}

it('reports no reverse charge for a domestic B2B customer', function (): void {
    $billable = displayBillable('AT', 'ATU12345678');

    expect($billable->usesReverseCharge())->toBeFalse();

    // And the charge agrees, which is the whole point of sharing the decision.
    expect(app(VatCalculationService::class)->calculate('AT', 10000, $billable)['vat'])->toBe(2000);
});

it('reports reverse charge for a cross-border B2B customer', function (): void {
    $billable = displayBillable('DE', 'DE123456789');

    expect($billable->usesReverseCharge())->toBeTrue();
    expect(app(VatCalculationService::class)->calculate('DE', 10000, $billable)['vat'])->toBe(0);
});

it('reports no reverse charge without a VAT number at all', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Consumer', 'billing_country' => 'DE']);

    expect($billable->usesReverseCharge())->toBeFalse();
});
