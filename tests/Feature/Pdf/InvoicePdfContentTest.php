<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\PdfText;
use Illuminate\Support\Facades\Storage;

/*
 * What is written INTO the PDFs the package stores — read back with a PHP PDF parser, so
 * the check runs everywhere (it used to need `pdftotext`, and was skipped without it).
 * Rendered by the real template through DOMPDF; nothing is stubbed.
 */

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.path', 'billing/invoices');
    config()->set('mollie-billing.invoices.seller.company', 'Graystack Test GmbH');
    config()->set('mollie-billing.invoices.seller.tax_number', 'ATU12345678');
    config()->set('mollie-billing.invoices.seller.address.street', 'Teststraße 1');
    config()->set('mollie-billing.invoices.seller.address.city', 'Wien');
    config()->set('mollie-billing.invoices.seller.address.postal_code', '1010');
    config()->set('mollie-billing.invoices.seller.address.country', 'AT');
});

function pdfContentInvoice(): BillingInvoice
{
    $billable = new TestBillable;
    $billable->forceFill([
        'email' => uniqid('pdf-').'@example.test',
        'name' => 'Kundin Köln GmbH',
        'billing_street' => 'Domplatz 7',
        'billing_postal_code' => '50667',
        'billing_city' => 'Köln',
        'billing_country' => 'DE',
    ])->save();

    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_pdf_'),
        'serial_number' => 'PDF-2027-0042',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 3900,
        'amount_vat' => 660,
        'amount_gross' => 4560,
        'refunded_net' => 0,
        'line_items' => [
            [
                'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro plan (monthly)', 'quantity' => 1,
                'unit_price_net' => 3000, 'amount_net' => 3000,
                'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600,
            ],
            [
                'kind' => 'addon', 'code' => 'print', 'label' => 'Print Gateway', 'quantity' => 1,
                'unit_price_net' => 900, 'amount_net' => 900,
                'vat_rate' => 10.0, 'vat_amount' => 90, 'amount_gross' => 990,
            ],
        ],
    ])->save();

    return $invoice;
}

it('writes the serial, both parties and every line into the invoice PDF', function (): void {
    $invoice = pdfContentInvoice();

    expect(app(InvoiceService::class)->regeneratePdf($invoice))->toBeTrue();

    $invoice->refresh();
    $bytes = (string) Storage::disk($invoice->pdf_disk)->get($invoice->pdf_path);
    $text = PdfText::of($bytes);

    expect($bytes)->toStartWith('%PDF')
        ->and($text)->toContain('PDF-2027-0042')
        ->and($text)->toContain('Graystack Test GmbH')
        ->and($text)->toContain('ATU12345678')
        ->and($text)->toContain('Kundin Köln GmbH')
        ->and($text)->toContain('Pro plan (monthly)')
        ->and($text)->toContain('Print Gateway');
});

it('writes the credit note for a refund as a document of its own, naming what it credits', function (): void {
    $invoice = pdfContentInvoice();
    app(InvoiceService::class)->regeneratePdf($invoice);

    $creditNote = app(InvoiceService::class)->createCreditNote($invoice->refresh(), 3900);
    $creditNote->refresh();

    expect($creditNote->hasPdf())->toBeTrue()
        ->and($creditNote->pdf_path)->not->toBe($invoice->pdf_path);

    $text = PdfText::of((string) Storage::disk($creditNote->pdf_disk)->get($creditNote->pdf_path));

    expect($text)->toContain((string) $creditNote->serial_number)
        ->and($text)->toContain('Kundin Köln GmbH');

    foreach ((array) $creditNote->line_items as $line) {
        expect($text)->toContain(PdfText::squash((string) $line['label']));
    }

    // Each credit line names the invoice it reverses.
    expect($text)->toContain('PDF-2027-0042');
});
