<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mpociot\VatCalculator\VatCalculator;

/**
 * Stub calculator: hardcoded EU rates, no network. Kept separate from
 * VatCalculationTest's FakeVatCalculator so this file can run on its own.
 */
final class DomesticReverseChargeCalculator extends VatCalculator
{
    /** @var array<string, float> */
    private array $rates = [
        'AT' => 0.20,
        'DE' => 0.19,
        'GR' => 0.24,
        'NL' => 0.21,
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
    $this->app->instance(VatCalculator::class, new DomesticReverseChargeCalculator());
});

function b2bBillable(string $billingCountry, string $vatNumber, string $validationCountry, bool $valid = true): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme GmbH',
        'billing_country' => $billingCountry,
        'vat_number' => $vatNumber,
    ]);

    $billable->vatValidations()->create([
        'vat_number' => $vatNumber,
        'country_code' => $validationCountry,
        'valid' => $valid,
        'vies_response' => ['valid' => $valid],
        'checked_at' => now(),
    ]);

    return $billable;
}

it('charges domestic VAT for a B2B customer in the seller country', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', 'AT');

    $billable = b2bBillable('AT', 'ATU12345678', 'AT');

    $result = app(VatCalculationService::class)->calculate('AT', 10000, $billable);

    // Art. 196 reverse charge is for cross-border supplies only; an Austrian
    // merchant invoicing an Austrian business owes 20% Austrian output VAT.
    expect($result['rate'])->toBe(20.0);
    expect($result['vat'])->toBe(2000);
    expect($result['gross'])->toBe(12000);
});

it('applies reverse charge for a B2B customer outside the seller country', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', 'AT');

    $billable = b2bBillable('DE', 'DE123456789', 'DE');

    $result = app(VatCalculationService::class)->calculate('DE', 10000, $billable);

    expect($result['rate'])->toBe(0.0);
    expect($result['vat'])->toBe(0);
    expect($result['gross'])->toBe(10000);
});

it('prefers vat.seller_country over the invoice seller address', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', 'NL');
    config()->set('mollie-billing.vat.seller_country', 'DE');

    $billable = b2bBillable('DE', 'DE123456789', 'DE');

    // Seller is DE per the explicit override → the DE customer is domestic.
    expect(app(VatCalculationService::class)->calculate('DE', 10000, $billable)['rate'])->toBe(19.0);
});

it('grants reverse charge but warns when the seller country is unconfigured', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', null);
    config()->set('mollie-billing.vat.seller_country', null);

    $billable = b2bBillable('AT', 'ATU12345678', 'AT');

    // Backwards-compatible default: domestic and cross-border are
    // indistinguishable without a seller country, so the VIES verdict decides.
    expect(app(VatCalculationService::class)->calculate('AT', 10000, $billable)['vat'])->toBe(0);
});

it('refuses reverse charge without a seller country when require_seller_country is on', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', null);
    config()->set('mollie-billing.vat.require_seller_country', true);

    $billable = b2bBillable('AT', 'ATU12345678', 'AT');

    expect(app(VatCalculationService::class)->calculate('AT', 10000, $billable)['vat'])->toBe(2000);
});

it('treats a domestic invoice country as domestic even when the VAT id is foreign', function (): void {
    config()->set('mollie-billing.invoices.seller.address.country', 'AT');

    // Contradictory data (DE VAT id, AT billing address) — the place of supply
    // being the seller's own country wins, so VAT is charged.
    $billable = b2bBillable('AT', 'DE123456789', 'DE');

    expect(app(VatCalculationService::class)->calculate('AT', 10000, $billable)['rate'])->toBe(20.0);
});

it('matches EL against GR when deciding domestic vs cross-border', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'GR');

    // Validation rows persist the ISO code, but legacy rows may hold the EL prefix.
    $billable = b2bBillable('GR', 'EL123456789', 'EL');

    expect(app(VatCalculationService::class)->calculate('GR', 10000, $billable)['rate'])->toBe(24.0);
});

it('applies reverse charge for a Greek business buying from a German seller', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'DE');

    $billable = b2bBillable('GR', 'EL123456789', 'GR');

    expect(app(VatCalculationService::class)->calculate('GR', 10000, $billable)['vat'])->toBe(0);
});

it('exposes the same decision through reverseChargeApplies as calculate uses', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $domestic = b2bBillable('AT', 'ATU12345678', 'AT');
    $crossBorder = b2bBillable('DE', 'DE123456789', 'DE');

    /** @var VatCalculationService $service */
    $service = app(VatCalculationService::class);

    expect($service->reverseChargeApplies($domestic))->toBeFalse();
    expect($service->reverseChargeApplies($crossBorder))->toBeTrue();
    expect($service->sellerCountry())->toBe('AT');
});

it('books zero VAT on every line of a reverse-charge invoice, not only in the header', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing.currency', 'EUR');

    $billable = b2bBillable('DE', 'DE123456789', 'DE');

    // Overage-shaped lines carry no own `vat_rate`, so they inherit the
    // reverse-charge rate from calculate(). InvoiceService stores VAT per line
    // and sums the header from it, so a per-line leak would show up twice.
    $invoice = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_rc_'.uniqid(), 'amount' => (object) ['value' => '30.00', 'currency' => 'EUR']],
        'overage',
        [
            ['kind' => 'overage', 'code' => 'Tokens', 'label' => 'Overage: Tokens', 'quantity' => 200, 'unit_price_net' => 5, 'total_net' => 1000],
            ['kind' => 'overage', 'code' => 'SMS', 'label' => 'Overage: SMS', 'quantity' => 100, 'unit_price_net' => 20, 'total_net' => 2000],
        ],
        $billable,
    );

    expect((int) $invoice->amount_vat)->toBe(0);
    expect((int) $invoice->amount_net)->toBe(3000);
    expect((int) $invoice->amount_gross)->toBe(3000);

    foreach ((array) $invoice->line_items as $line) {
        expect((float) $line['vat_rate'])->toBe(0.0);
        expect((int) $line['vat_amount'])->toBe(0);
        expect((int) $line['amount_gross'])->toBe((int) $line['amount_net']);
    }
});

it('books domestic VAT on every line when reverse charge does not apply', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing.currency', 'EUR');

    // Domestic B2B: the same lines must now carry 20% on each line.
    $billable = b2bBillable('AT', 'ATU12345678', 'AT');

    $invoice = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_dom_'.uniqid(), 'amount' => (object) ['value' => '36.00', 'currency' => 'EUR']],
        'overage',
        [
            ['kind' => 'overage', 'code' => 'Tokens', 'label' => 'Overage: Tokens', 'quantity' => 200, 'unit_price_net' => 5, 'total_net' => 1000],
            ['kind' => 'overage', 'code' => 'SMS', 'label' => 'Overage: SMS', 'quantity' => 100, 'unit_price_net' => 20, 'total_net' => 2000],
        ],
        $billable,
    );

    expect((int) $invoice->amount_vat)->toBe(600);

    foreach ((array) $invoice->line_items as $line) {
        expect((float) $line['vat_rate'])->toBe(20.0);
        expect((int) $line['vat_amount'])->toBe((int) round($line['amount_net'] * 0.2));
    }
});

it('never applies reverse charge without a valid persisted validation', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $invalid = b2bBillable('DE', 'DE999999999', 'DE', valid: false);

    expect(app(VatCalculationService::class)->reverseChargeApplies($invalid))->toBeFalse();
    expect(app(VatCalculationService::class)->calculate('DE', 10000, $invalid)['rate'])->toBe(19.0);
});
