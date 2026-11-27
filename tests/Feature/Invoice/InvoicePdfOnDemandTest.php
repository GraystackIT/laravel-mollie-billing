<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\AuthorizesBillingAdmin;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\InvoicePdfRegenerated;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Decision 13 (2026-11-26): the PDF link of an invoice is always shown, and a document
 * that is not on the disk yet — a deferred render still to come, a render that failed —
 * is produced when the link is clicked.
 *
 * Every invoice row is persisted with its serial (`persistWithSerial()`), so every row
 * is one that will have a document: no screen hides the link behind `hasPdf()` any more.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.path', 'billing/invoices');
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.currency', 'EUR');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

class PdfOnDemandAdminUser extends Authenticatable implements AuthorizesBillingAdmin
{
    public function canAccessBillingAdmin(): bool
    {
        return true;
    }
}

function pdfOnDemandBillable(): TestBillable
{
    $billable = new TestBillable;
    $billable->forceFill([
        'name' => 'On Demand GmbH',
        'email' => uniqid('on-demand-').'@example.test',
        'billing_country' => 'DE',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'mollie_customer_id' => 'cst_on_demand',
        'mollie_mandate_id' => 'mdt_on_demand',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_on_demand'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** An invoice as the books hold it between commit and its (deferred or failed) render: serial, no document. */
function pdfOnDemandInvoice(TestBillable $billable, array $attributes = []): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->forceFill(array_merge([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_ondemand_'),
        'serial_number' => 'RE-OD-'.random_int(100000, 999999),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 190,
        'amount_gross' => 1190,
        'refunded_net' => 0,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 1000, 'amount_net' => 1000, 'total_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
        'pdf_disk' => null,
        'pdf_path' => null,
    ], $attributes))->save();

    return $invoice;
}

/**
 * A renderer that counts its runs and records whether the per-invoice document lock was
 * held by someone else while it ran (it is, when the caller took it).
 */
function pdfOnDemandCountingService(): object
{
    $probe = new class
    {
        public int $renders = 0;

        /** @var list<bool> */
        public array $lockHeld = [];
    };

    app()->bind(InvoiceService::class, function ($app) use ($probe): InvoiceService {
        return new class ($app->make(VatCalculationService::class), $app->make(InvoiceNumberGenerator::class), $probe) extends InvoiceService
        {
            public function __construct(VatCalculationService $vat, InvoiceNumberGenerator $numbers, private object $probe)
            {
                parent::__construct($vat, $numbers);
            }

            protected function generateAndStorePdf(BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable): bool
            {
                $this->probe->renders++;

                $probeLock = Cache::lock('billing:invoice-pdf-regenerate:'.$invoice->id, 5);
                $acquired = $probeLock->get();
                $this->probe->lockHeld[] = ! $acquired;
                if ($acquired) {
                    $probeLock->release();
                }

                $invoice->pdf_disk = 'local';
                $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
                $invoice->save();
                Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 rendered');

                return true;
            }
        };
    });

    return $probe;
}

// ── The link is shown ───────────────────────────────────────────────────────

it('portal invoice list: offers the PDF link for an invoice whose document is not rendered yet', function (): void {
    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    expect($invoice->hasPdf())->toBeFalse();

    Livewire::test('mollie-billing::invoices')
        ->assertSeeHtml('invoices/'.$invoice->id.'/download');
});

it('portal dashboard: offers the PDF link for an invoice whose document is not rendered yet', function (): void {
    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    Livewire::test('mollie-billing::dashboard')
        ->assertSeeHtml('invoices/'.$invoice->id.'/download');
});

it('admin billable invoices tab: offers the download link for an invoice whose document is not rendered yet', function (): void {
    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    // Flux is not compiled in the feature suite (its tags come out verbatim), so the
    // assertion is on the gated block itself: the download button and its label.
    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->assertSeeHtml('icon="arrow-down-tray" :href="$pdfUrl"')
        ->assertSeeHtml('billing::admin.billables.invoices.download_pdf_aria');
});

it('admin refund detail: offers the credit note and the original invoice link when neither is rendered yet', function (): void {
    $billable = pdfOnDemandBillable();
    $original = pdfOnDemandInvoice($billable);
    $creditNote = pdfOnDemandInvoice($billable, [
        'mollie_payment_id' => null,
        'serial_number' => 'GS-OD-'.random_int(100000, 999999),
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'amount_net' => -500, 'amount_vat' => -95, 'amount_gross' => -595,
        'line_items' => [[
            'kind' => 'plan', 'label' => 'Pro', 'quantity' => 1, 'parent_invoice_id' => $original->id,
            'amount_net' => -500, 'vat_rate' => 19.0, 'vat_amount' => -95, 'amount_gross' => -595,
        ]],
    ]);

    Livewire::test('mollie-billing::admin.refunds.index')
        ->call('openDetail', $creditNote->id)
        // Flux tags come out verbatim in the feature suite: asserted on the gated buttons.
        ->assertSeeHtml(':href="$refundDownloadUrl"')
        ->assertSeeHtml(':href="$parentDownloadUrl"');
});

// ── The click produces the document ────────────────────────────────────────

it('download: renders and stores a missing document on the click, keeping the serial number', function (): void {
    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);
    $serial = $invoice->serial_number;
    $invoiceCount = BillingInvoice::query()->count();

    $response = $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]));

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('pdf');

    $invoice->refresh();
    expect($invoice->hasPdf())->toBeTrue()
        ->and(Storage::disk('local')->exists($invoice->pdf_path))->toBeTrue()
        ->and(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF')
        // Rendering is not booking: the serial the row was persisted with is the one on
        // the document, and no new row (no new serial) was drawn.
        ->and($invoice->serial_number)->toBe($serial)
        ->and(BillingInvoice::query()->count())->toBe($invoiceCount);
});

it('download: an admin click on the admin route renders the missing document too', function (): void {
    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    $this->actingAs(new PdfOnDemandAdminUser)
        ->get(route(BillingRoute::admin('invoice.download'), ['invoice' => $invoice]))
        ->assertOk();

    expect($invoice->fresh()->hasPdf())->toBeTrue();
});

it('download: a second click serves the stored document instead of rendering again', function (): void {
    $probe = pdfOnDemandCountingService();
    Event::fake([InvoicePdfRegenerated::class]);

    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))->assertOk();
    $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))->assertOk();

    expect($probe->renders)->toBe(1)
        // The click renders under the per-invoice lock that serialises concurrent clicks.
        ->and($probe->lockHeld)->toBe([true]);

    Event::assertDispatchedTimes(InvoicePdfRegenerated::class, 1);
});

it('download: authorization is unchanged — a foreign tenant is refused and nothing is rendered', function (): void {
    $probe = pdfOnDemandCountingService();
    MollieBilling::authUsing(fn (): bool => false);

    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))->assertForbidden();

    expect($probe->renders)->toBe(0)
        ->and($invoice->fresh()->hasPdf())->toBeFalse();
});

// ── The deferred render and the click share one lock ───────────────────────

it('deferred credit-note document: renders under the same per-invoice lock a download click takes', function (): void {
    $probe = pdfOnDemandCountingService();

    $billable = pdfOnDemandBillable();
    $original = pdfOnDemandInvoice($billable);
    $creditNote = pdfOnDemandInvoice($billable, ['invoice_kind' => InvoiceKind::Refund, 'mollie_payment_id' => null]);

    app(InvoiceService::class)->issueCreditNoteDocument($creditNote, $original);

    expect($probe->renders)->toBe(1)
        ->and($probe->lockHeld)->toBe([true])
        ->and($creditNote->hasPdf())->toBeTrue();
});

it('deferred invoice document: does not render again what a download click already rendered', function (): void {
    $probe = pdfOnDemandCountingService();

    $billable = pdfOnDemandBillable();
    $invoice = pdfOnDemandInvoice($billable);

    // The customer clicked between the commit and the deferred render.
    $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))->assertOk();
    expect($probe->renders)->toBe(1);

    $stale = BillingInvoice::query()->find($invoice->id);
    $stale->forceFill(['pdf_disk' => null, 'pdf_path' => null])->syncOriginal();

    app(InvoiceService::class)->issueInvoiceDocument($stale, $billable);

    expect($probe->renders)->toBe(1)
        // The caller's model carries the stored document afterwards.
        ->and($stale->hasPdf())->toBeTrue()
        ->and($stale->pdf_path)->toBe($invoice->fresh()->pdf_path);
});
