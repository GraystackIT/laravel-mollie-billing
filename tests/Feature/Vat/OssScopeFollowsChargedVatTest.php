<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * The OSS export's scope follows the VAT a supply actually carries.
 *
 * `scopeFor()` used to label a row `reverse_charge` as soon as the invoice pointed at a
 * valid VIES validation, without looking at whether the supply WAS reverse-charged.
 * `vat_validation_id` is not that statement: it records the validation on file when the
 * document was written — also when the calculation then refused reverse charge
 * (`vat.require_seller_country` without a seller country), and, for plan-change credit
 * notes written before they inherited their original's validation, the customer's status
 * at the time of the refund. So a consumer sale at 19 % whose buyer later registered a
 * VAT number and downgraded had its credit note filed under `reverse_charge`: the OSS row
 * kept the full sale, and the correction carried negative VAT on a zero-rated list.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.oss.disk', 'local');
    config()->set('mollie-billing.oss.path', 'billing/oss-exports');
    config()->set('mollie-billing.vat.seller_country', 'AT');

    $this->billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 'tester@example.com',
        'billing_country' => 'DE',
    ]);
});

function ossScopeInvoice(TestBillable $billable, int $net, int $vat, InvoiceKind $kind, ?int $validationId): BillingInvoice
{
    $createdAt = CarbonImmutable::create(2026, 2, 15, 12, 0, 0, 'UTC');

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => $kind === InvoiceKind::Refund ? null : 'tr_oss_'.uniqid(),
        'serial_number' => 'OSS-'.uniqid(),
        'invoice_kind' => $kind,
        'status' => $kind === InvoiceKind::Refund ? InvoiceStatus::Refunded : InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'vat_validation_id' => $validationId,
        'line_items' => [[
            'kind' => $kind === InvoiceKind::Refund ? 'refund' : 'plan',
            'code' => 'pro',
            'label' => 'Pro Plan',
            'quantity' => 1,
            'amount_net' => $net,
            'vat_rate' => 19.0,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function ossScopeValidValidation(TestBillable $billable): BillingVatValidation
{
    return BillingVatValidation::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'vat_number' => 'DE123456789',
        'country_code' => 'DE',
        'valid' => true,
        'vies_response' => [],
        'checked_at' => now(),
    ]);
}

/** @return array<string, array<int, string>> rows keyed by scope */
function ossScopeRowsByScope(string $path): array
{
    $raw = trim((string) Storage::disk('local')->get($path));
    $rows = array_map('str_getcsv', explode("\n", $raw));

    $byScope = [];
    foreach (array_slice($rows, 1) as $row) {
        $byScope[$row[7]] = $row;
    }

    return $byScope;
}

it('does not file a supply that carries VAT under reverse_charge', function (): void {
    // 100,00 € net + 19,00 € German VAT actually charged, on a billable with a valid number.
    ossScopeInvoice($this->billable, 10000, 1900, InvoiceKind::Subscription, ossScopeValidValidation($this->billable)->id);

    $rows = ossScopeRowsByScope((new OssProtocolService())->export(2026)->path);

    expect(array_keys($rows))->not->toContain('reverse_charge');
});

it('nets a consumer sale and its later credit note in the same OSS row', function (): void {
    // Sold B2C: no validation on the invoice.
    ossScopeInvoice($this->billable, 10000, 1900, InvoiceKind::Subscription, null);

    // Credit note for half of it, written after the customer registered a VAT number.
    ossScopeInvoice($this->billable, -5000, -950, InvoiceKind::Refund, ossScopeValidValidation($this->billable)->id);

    $rows = ossScopeRowsByScope((new OssProtocolService())->export(2026)->path);

    expect($rows)->toHaveKey('oss')
        ->and($rows['oss'][4])->toBe('50.00')
        ->and($rows['oss'][5])->toBe('9.50');
});

it('still files a zero-rated supply to a validated business customer under reverse_charge', function (): void {
    $validationId = ossScopeValidValidation($this->billable)->id;

    $invoice = ossScopeInvoice($this->billable, 10000, 0, InvoiceKind::Subscription, $validationId);
    $lines = $invoice->line_items;
    $lines[0]['vat_rate'] = 0.0;
    $invoice->forceFill(['line_items' => $lines])->save();

    $rows = ossScopeRowsByScope((new OssProtocolService())->export(2026)->path);

    expect(array_keys($rows))->toBe(['reverse_charge'])
        ->and($rows['reverse_charge'][4])->toBe('100.00')
        ->and($rows['reverse_charge'][5])->toBe('0.00');
});
