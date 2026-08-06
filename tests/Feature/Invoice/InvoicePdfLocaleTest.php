<?php

declare(strict_types=1);

use Elegantly\Invoices\Pdf\PdfInvoice;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * The renderer formats money and labels through the active locale, so a PDF
 * regenerated from an admin request must not be reformatted: the rendering
 * locale is pinned, never inherited from whoever triggers the render.
 */

/** Records the locale the renderer would run under, without invoking DOMPDF. */
class LocaleRecordingInvoiceService extends InvoiceService
{
    public ?string $renderLocale = null;

    protected function buildPdfInvoice(BillingInvoice $invoice, Billable $billable): PdfInvoice
    {
        $this->renderLocale = app()->getLocale();

        throw new \RuntimeException('skip the real render');
    }
}

/** Exposes buildPdfInvoice for label assertions. */
class PdfExposingInvoiceService extends InvoiceService
{
    public function pdfFor(BillingInvoice $invoice, Billable $billable): PdfInvoice
    {
        return $this->buildPdfInvoice($invoice, $billable);
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('app.fallback_locale', 'de');
    config()->set('mollie-billing.invoices.locale', null);
});

function localeBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'locale@example.com',
        'name' => 'Locale Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function localeInvoice(TestBillable $billable, InvoiceStatus $status = InvoiceStatus::Paid): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_locale_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(1000, 999999);
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = $status;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->line_items = [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 1000, 'amount_net' => 1000,
        'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

function renderLocaleFor(BillingInvoice $invoice, TestBillable $billable): ?string
{
    $svc = new LocaleRecordingInvoiceService(app(VatCalculationService::class), app(InvoiceNumberGenerator::class));
    (new ReflectionMethod(InvoiceService::class, 'generateAndStorePdf'))->invoke($svc, $invoice, $billable);

    return $svc->renderLocale;
}

it('renders in the pinned locale rather than the active request locale', function (): void {
    $b = localeBillable();
    $invoice = localeInvoice($b);

    // An admin panel pinned to English regenerates the document. App::setLocale()
    // also rewrites config('app.locale'), so only a locale that setLocale cannot
    // touch keeps a regenerated document identical to the original.
    app()->setLocale('en');

    expect(renderLocaleFor($invoice, $b))->toBe('de')
        // ...and the request locale is left exactly as it was.
        ->and(app()->getLocale())->toBe('en');
});

it('honours an explicitly configured invoice locale', function (): void {
    config()->set('mollie-billing.invoices.locale', 'fr');

    $b = localeBillable();
    $invoice = localeInvoice($b);
    app()->setLocale('en');

    expect(renderLocaleFor($invoice, $b))->toBe('fr');
});

it('prefers a locale stored on the invoice when the column exists', function (): void {
    $b = localeBillable();
    $invoice = localeInvoice($b);

    // The `locale` column is optional (added by a follow-up migration); simulate
    // a populated value to prove it wins over the configured default.
    $invoice->setAttribute('locale', 'it');

    expect(renderLocaleFor($invoice, $b))->toBe('it');
});

it('renders the failed state with a translated label, not the raw key', function (): void {
    $b = localeBillable();
    $invoice = localeInvoice($b, InvoiceStatus::Failed);

    $svc = new PdfExposingInvoiceService(app(VatCalculationService::class), app(InvoiceNumberGenerator::class));
    $state = $svc->pdfFor($invoice, $b)->getStateLabel();

    expect($state)->not->toContain('billing::portal')
        ->and($state)->toBe(__('billing::portal.invoice_state_failed'));
});

it('keeps the invoice-state and credit-note keys in en/de parity', function (): void {
    $en = require __DIR__.'/../../../resources/lang/en/portal.php';
    $de = require __DIR__.'/../../../resources/lang/de/portal.php';

    $keys = ['invoice_state_paid', 'invoice_state_refunded', 'invoice_state_pending', 'invoice_state_failed', 'invoice_state_draft', 'credit_note_label', 'credit_note_line_label'];

    foreach ($keys as $key) {
        expect($en)->toHaveKey($key)
            ->and($de)->toHaveKey($key);

        preg_match_all('/:\w+/', (string) $en[$key], $enPlaceholders);
        preg_match_all('/:\w+/', (string) $de[$key], $dePlaceholders);

        expect($dePlaceholders[0])->toBe($enPlaceholders[0], "placeholder mismatch for {$key}");
    }
});
