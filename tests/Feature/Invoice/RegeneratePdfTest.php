<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Events\InvoicePdfRegenerated;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.path', 'billing/invoices');

    // Stub PDF generation: write a deterministic placeholder file instead of running DOMPDF.
    $this->app->bind(InvoiceService::class, function ($app): InvoiceService {
        return new class ($app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService {
            protected function generateAndStorePdf(BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable): void
            {
                $invoice->pdf_disk = 'local';
                $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'-'.uniqid().'.pdf';
                $invoice->save();

                Storage::disk('local')->put($invoice->pdf_path, 'rendered-pdf-bytes');
            }
        };
    });
});

function makeInvoiceWithPdf(): BillingInvoice
{
    $billable = new TestBillable;
    $billable->forceFill([
        'email' => 'invoice-regen@example.com',
        'name' => 'Regen Test',
        'billing_country' => 'DE',
    ])->save();

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->serial_number = 'INV-REGEN-1';
    $invoice->invoice_kind = \GraystackIT\MollieBilling\Enums\InvoiceKind::Subscription;
    $invoice->status = \GraystackIT\MollieBilling\Enums\InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->line_items = [[
        'kind' => 'plan',
        'label' => 'Pro',
        'amount_net' => 1000,
        'vat_rate' => 19.0,
        'vat_amount' => 190,
        'amount_gross' => 1190,
    ]];
    $invoice->refunded_net = 0;
    $invoice->pdf_disk = 'local';
    $invoice->pdf_path = 'billing/invoices/test/INV-REGEN-1-original.pdf';
    $invoice->save();

    Storage::disk('local')->put($invoice->pdf_path, 'original-pdf-bytes');

    return $invoice;
}

it('regenerates the PDF and deletes the previous file', function (): void {
    Event::fake([InvoicePdfRegenerated::class]);

    $invoice = makeInvoiceWithPdf();
    $originalPath = $invoice->pdf_path;

    expect(Storage::disk('local')->exists($originalPath))->toBeTrue();

    $success = app(InvoiceService::class)->regeneratePdf($invoice);

    expect($success)->toBeTrue();
    expect(Storage::disk('local')->exists($originalPath))->toBeFalse();

    $fresh = $invoice->fresh();
    expect($fresh->hasPdf())->toBeTrue();
    expect($fresh->pdf_path)->not->toBe($originalPath);
    expect(Storage::disk('local')->exists($fresh->pdf_path))->toBeTrue();

    Event::assertDispatched(InvoicePdfRegenerated::class, fn ($e) => $e->invoice->is($fresh));
});

it('regenerates a PDF even when none existed before', function (): void {
    Event::fake([InvoicePdfRegenerated::class]);

    $invoice = makeInvoiceWithPdf();
    $invoice->pdf_path = null;
    $invoice->pdf_disk = null;
    $invoice->save();

    $success = app(InvoiceService::class)->regeneratePdf($invoice);

    expect($success)->toBeTrue();
    expect($invoice->fresh()->hasPdf())->toBeTrue();

    Event::assertDispatched(InvoicePdfRegenerated::class);
});

it('returns false and skips the event when the renderer fails', function (): void {
    Event::fake([InvoicePdfRegenerated::class]);

    // Override with a stub that silently fails to write a PDF.
    $this->app->bind(InvoiceService::class, function ($app): InvoiceService {
        return new class ($app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService {
            protected function generateAndStorePdf(BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable): void
            {
                // Renderer failure path — log + leave pdf_path null.
            }
        };
    });

    $invoice = makeInvoiceWithPdf();

    $success = app(InvoiceService::class)->regeneratePdf($invoice);

    expect($success)->toBeFalse();
    expect($invoice->fresh()->hasPdf())->toBeFalse();

    Event::assertNotDispatched(InvoicePdfRegenerated::class);
});
