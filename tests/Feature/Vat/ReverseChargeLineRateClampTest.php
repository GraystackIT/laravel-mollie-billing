<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mpociot\VatCalculator\VatCalculator;

/**
 * `createForPayment()` is public API and a caller may hand it a per-line
 * `vat_rate` for mixed-rate invoices. That override must not be able to put VAT
 * back on a supply the country lookup decided is reverse-charged: the invoice
 * would collect tax the customer owes in their own country, and the OSS export
 * would report it.
 */

/** Stub calculator: hardcoded EU rates, no network. */
final class LineRateClampVatCalculator extends VatCalculator
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
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $this->app->instance(VatCalculator::class, new LineRateClampVatCalculator);
});

function clampBillable(string $billingCountry, string $vatNumber): TestBillable
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

/** @return list<array<string, mixed>> */
function clampLines(float $callerRate): array
{
    return [
        ['kind' => 'plan', 'label' => 'Pro', 'code' => 'pro', 'quantity' => 1, 'unit_price' => 1000, 'unit_price_net' => 1000, 'total_net' => 1000],
        // A caller-supplied per-line rate, as an app-side emitter may pass it.
        ['kind' => 'addon', 'label' => 'Print', 'code' => 'print', 'quantity' => 1, 'unit_price' => 500, 'unit_price_net' => 500, 'total_net' => 500, 'vat_rate' => $callerRate],
    ];
}

it('clamps a caller-supplied line rate to zero on a reverse-charged supply', function (): void {
    // Cross-border EU B2B with a valid VIES entry: reverse charge, 0%.
    $billable = clampBillable('DE', 'DE123456789');

    $invoice = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_clamp_rc', 'subscriptionId' => null],
        'subscription',
        clampLines(20.0),
        $billable,
    );

    expect((int) $invoice->amount_vat)->toBe(0)
        ->and((int) $invoice->amount_net)->toBe(1500)
        ->and((int) $invoice->amount_gross)->toBe(1500);

    foreach ((array) $invoice->line_items as $line) {
        expect((float) $line['vat_rate'])->toBe(0.0)
            ->and((int) $line['vat_amount'])->toBe(0)
            ->and((int) $line['amount_gross'])->toBe((int) $line['amount_net']);
    }
});

it('still honours a per-line rate where the supply is taxed', function (): void {
    // Domestic B2B: reverse charge does not apply, so a genuinely mixed-rate
    // invoice must keep the caller's 10% on the addon line.
    $billable = clampBillable('AT', 'ATU12345678');

    $invoice = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_clamp_domestic', 'subscriptionId' => null],
        'subscription',
        clampLines(10.0),
        $billable,
    );

    $lines = (array) $invoice->line_items;

    expect((float) $lines[0]['vat_rate'])->toBe(20.0)
        ->and((int) $lines[0]['vat_amount'])->toBe(200)
        ->and((float) $lines[1]['vat_rate'])->toBe(10.0)
        ->and((int) $lines[1]['vat_amount'])->toBe(50)
        ->and((int) $invoice->amount_vat)->toBe(250);
});
