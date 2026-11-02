<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;

// The PDF is the document of what was booked, so its total has to be `amount_gross`.
// Given only a percentage the renderer recomputes `net × rate` — and the stored VAT is not
// always that number, because a partial credit note allocates its VAT across the lines it
// reverses instead of recomputing each one.

beforeEach(function (): void {
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'ACME', 'name' => 'Jane', 'email' => 'billing@acme.test', 'tax_number' => 'ATU12345678',
        'address' => ['street' => 'Main 1', 'city' => 'Vienna', 'postal_code' => '1010', 'country' => 'AT'],
    ]);
});

function pdfTotalFor(BillingInvoice $invoice, TestBillable $billable): int
{
    $method = new ReflectionMethod(InvoiceService::class, 'buildPdfInvoice');
    $method->setAccessible(true);

    return $method->invoke(app(InvoiceService::class), $invoice, $billable)
        ->totalAmount()->getMinorAmount()->toInt();
}

it('totals the PDF to the gross the invoice was booked with', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Pdf Co', 'email' => 'pdf@example.test', 'billing_country' => 'AT']);

    // 333 at 19% recomputes to 63; this line was booked with 62, which is what an
    // allocated credit-note VAT looks like.
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_pdf',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 333,
        'amount_vat' => 62,
        'amount_gross' => 395,
        'serial_number' => 'IN-pdf-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 333, 'amount_net' => 333,
            'vat_rate' => 19.0, 'vat_amount' => 62, 'amount_gross' => 395,
        ]],
    ]);

    expect(pdfTotalFor($invoice, $billable))->toBe((int) $invoice->amount_gross);
});

it('keeps a multi-unit line rendered per unit', function (): void {
    // The stored VAT is only usable when it divides evenly by the quantity — the renderer
    // multiplies `unit_tax` by it. Where it does not, the rate is used as before, and the
    // document still has to add up.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Seats Co', 'email' => 'seats-pdf@example.test', 'billing_country' => 'AT']);

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_pdf_seats',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 3000,
        'amount_vat' => 600,
        'amount_gross' => 3600,
        'serial_number' => 'IN-pdf-seats-'.uniqid(),
        'line_items' => [[
            'kind' => 'seat', 'label' => 'Seats', 'quantity' => 3,
            'unit_price_net' => 1000, 'amount_net' => 3000,
            'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600,
        ]],
    ]);

    expect(pdfTotalFor($invoice, $billable))->toBe((int) $invoice->amount_gross);
});

it('renders a refund reason as text, not as markup', function (): void {
    // The vendor template prints the description with `{!! !!}` and ships DOMPDF with
    // `isRemoteEnabled`, so an `<img src>` typed into the admin refund form was fetched by
    // the server while rendering — an SSRF to whatever address it named — and any other
    // markup landed on a legal document as such.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Refund Co', 'email' => 'refund-pdf@example.test', 'billing_country' => 'AT']);

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => null,
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => -1000,
        'amount_vat' => -200,
        'amount_gross' => -1200,
        'serial_number' => 'CN-pdf-'.uniqid(),
        'refund_reason_text' => "Goodwill <img src=\"http://169.254.169.254/latest/meta-data/\">\nsecond line",
        'line_items' => [[
            'kind' => 'plan', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => -1000, 'amount_net' => -1000,
            'vat_rate' => 20.0, 'vat_amount' => -200, 'amount_gross' => -1200,
        ]],
    ]);

    $method = new ReflectionMethod(InvoiceService::class, 'buildPdfInvoice');
    $method->setAccessible(true);
    $description = $method->invoke(app(InvoiceService::class), $invoice, $billable)->description;

    expect($description)->not->toContain('<img')
        ->and($description)->toContain('&lt;img')
        // The template renders with `whitespace-pre-line`, so line breaks survive escaping.
        ->and($description)->toContain("\nsecond line");
});
