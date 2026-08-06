<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Log;
use Mpociot\VatCalculator\VatCalculator;

/**
 * A malformed `vat.seller_country` used to flow straight into the domestic-vs-
 * cross-border comparison, where it simply never matched — so every domestic
 * B2B customer was reverse-charged at 0% and the seller owed that VAT.
 */
final class SellerCountryValidationCalculator extends VatCalculator
{
    /** @var array<string, float> */
    private array $rates = [
        'AT' => 0.20,
        'DE' => 0.19,
        'GR' => 0.24,
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
        return $this->getTaxRateForCountry($countryCode);
    }
}

beforeEach(function (): void {
    $this->app->instance(VatCalculator::class, new SellerCountryValidationCalculator());

    // Both keys default to the same env var, so a typo lands in both. Tests that
    // exercise the fallback set it explicitly.
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.invoices.seller.address.country', null);
    config()->set('mollie-billing.vat.require_seller_country', false);
});

function sellerCountryB2bBillable(string $billingCountry, string $vatNumber): TestBillable
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

it('rejects a seller country that is not an ISO alpha-2 code', function (string $configured): void {
    config()->set('mollie-billing.vat.seller_country', $configured);
    config()->set('mollie-billing.invoices.seller.address.country', $configured);

    $service = app(VatCalculationService::class);
    $domestic = sellerCountryB2bBillable('AT', 'ATU12345678');

    // The whole point: a domestic B2B supply must not be reverse-charged just
    // because the configured seller country can never equal 'AT'.
    expect($service->calculate('AT', 10000, $domestic))
        ->toMatchArray(['rate' => 20.0, 'vat' => 2000, 'gross' => 12000]);
    expect($service->reverseChargeApplies($domestic))->toBeFalse();
    expect($service->sellerCountry())->toBeNull();
})->with([
    'alpha-3 code' => 'AUT',
    'country name' => 'Austria',
    'single letter' => 'A',
    'letter plus digit' => 'A1',
    'two digits' => '40',
    'code with trailing digit' => 'AT1',
    'comma-separated list' => 'AT,DE',
    'locale tag' => 'de-AT',
    'punctuation only' => '--',
]);

it('still accepts the ISO alpha-2 shapes that only differ in case or padding', function (string $configured, string $expected): void {
    config()->set('mollie-billing.vat.seller_country', $configured);

    $service = app(VatCalculationService::class);

    expect($service->sellerCountry())->toBe($expected);
})->with([
    'canonical' => ['AT', 'AT'],
    'lowercase' => ['at', 'AT'],
    'mixed case' => ['aT', 'AT'],
    'padded' => ['  AT  ', 'AT'],
    'padded lowercase' => [" de\n", 'DE'],
    'VIES prefix for Greece' => ['EL', 'GR'],
]);

it('falls through to the invoice seller address when the explicit key is malformed', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AUT');
    config()->set('mollie-billing.invoices.seller.address.country', 'AT');

    $service = app(VatCalculationService::class);

    expect($service->sellerCountry())->toBe('AT');

    // A usable candidate exists, so the malformed one must not tighten anything.
    expect($service->reverseChargeApplies(sellerCountryB2bBillable('AT', 'ATU12345678')))->toBeFalse();
    expect($service->reverseChargeApplies(sellerCountryB2bBillable('DE', 'DE123456789')))->toBeTrue();
});

it('refuses reverse charge on a malformed seller country even for a cross-border customer', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'Deutschland');

    $service = app(VatCalculationService::class);
    $crossBorder = sellerCountryB2bBillable('AT', 'ATU12345678');

    // Without a usable seller country we cannot tell the two apart, and the
    // recoverable error is charging VAT — not paying it out of pocket.
    expect($service->reverseChargeApplies($crossBorder))->toBeFalse();
    expect($service->calculate('AT', 10000, $crossBorder)['vat'])->toBe(2000);
});

it('does not let require_seller_country=false rescue a malformed seller country', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'GERMANY');
    config()->set('mollie-billing.vat.require_seller_country', false);

    $service = app(VatCalculationService::class);

    // The lenient default covers "never configured" (pre-0.4 installs), not a
    // value the operator set and mistyped.
    expect($service->reverseChargePolicyWithoutSellerCountry())->toBeFalse();
    expect($service->reverseChargeApplies(sellerCountryB2bBillable('DE', 'DE123456789')))->toBeFalse();
});

it('keeps the unconfigured-seller-country contract intact', function (): void {
    $service = app(VatCalculationService::class);

    expect($service->reverseChargePolicyWithoutSellerCountry())->toBeTrue();
    expect($service->reverseChargeApplies(sellerCountryB2bBillable('AT', 'ATU12345678')))->toBeTrue();

    config()->set('mollie-billing.vat.require_seller_country', true);

    expect($service->reverseChargePolicyWithoutSellerCountry())->toBeFalse();
    expect($service->reverseChargeApplies(sellerCountryB2bBillable('DE', 'DE123456789')))->toBeFalse();
});

it('logs the rejected seller country so the typo is findable', function (): void {
    // Value is unique per test run: the once-per-process guard keys on it.
    $value = 'AUSTRIA-'.uniqid();
    config()->set('mollie-billing.vat.seller_country', $value);

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    app(VatCalculationService::class)->sellerCountry();

    expect(implode("\n", $logged))->toContain($value)->toContain('alpha-2');
});
