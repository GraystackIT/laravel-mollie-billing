<?php

declare(strict_types=1);

use Elegantly\Invoices\Pdf\PdfInvoice;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Http\Controllers\InvoiceDownloadController;
use GraystackIT\MollieBilling\Http\Controllers\OssExportDownloadController;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\AdminKpiService;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * One test per configuration key of the operations area that had none of its own:
 * each changes the value and asserts what changes with it. Keys that already have such
 * a test are claimed with that test in `tests/Coverage/operations.php`.
 */

/** Exposes the PDF value object, so seller and logo can be read without DOMPDF. */
class OperationsConfigPdfProbe extends InvoiceService
{
    public function pdfFor(BillingInvoice $invoice, Billable $billable): PdfInvoice
    {
        return $this->buildPdfInvoice($invoice, $billable);
    }
}

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

function operationsConfigBillable(array $attributes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Config Co',
        'email' => uniqid('config-').'@x.test',
        'billing_country' => 'DE',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ], $attributes))->save();

    return $billable->refresh();
}

function operationsConfigInvoice(TestBillable $billable, array $attributes = []): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->forceFill(array_merge([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_cfg_'),
        'serial_number' => 'IN-26'.random_int(100000, 999999),
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
            'unit_price_net' => 1000, 'amount_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
    ], $attributes))->save();

    return $invoice;
}

function operationsConfigLayout(string $layout): string
{
    return view('mollie-billing::layouts.'.$layout, [
        'livewireComponent' => $layout === 'admin' ? 'mollie-billing::admin.dashboard' : 'mollie-billing::dashboard',
        'backUrl' => null,
    ])->render();
}

// ── Branding ────────────────────────────────────────────────────────────────

it('logo_url — replaces the title mark with the configured image in both layouts', function (string $layout): void {
    config()->set('mollie-billing.logo_url', null);
    expect(operationsConfigLayout($layout))->not->toContain('<img src="https://cdn.example.test/brand.svg"');

    config()->set('mollie-billing.logo_url', 'https://cdn.example.test/brand.svg');
    expect(operationsConfigLayout($layout))->toContain('<img src="https://cdn.example.test/brand.svg"');
})->with(['admin', 'portal']);

it('favicon_url — is what the page links as its icon', function (string $layout): void {
    config()->set('mollie-billing.favicon_url', '/brand/icon.png');

    expect(operationsConfigLayout($layout))->toContain('<link rel="icon" href="/brand/icon.png">');
})->with(['admin', 'portal']);

it('primary_color — is the accent the layout is wrapped in', function (string $layout): void {
    config()->set('mollie-billing.primary_color', 'rose');
    expect(operationsConfigLayout($layout))->toContain('color="rose"');

    config()->set('mollie-billing.primary_color', 'indigo');
    expect(operationsConfigLayout($layout))->toContain('color="indigo"')->not->toContain('color="rose"');
})->with(['admin', 'portal']);

it('company_name — names the product in the page title, and app.name does when it is unset', function (): void {
    config()->set('app.name', 'Fallback App');

    config()->set('mollie-billing.company_name', 'Graystack Billing');
    expect(operationsConfigLayout('admin'))->toContain(e(__('billing::admin.title', ['app' => 'Graystack Billing'])));

    config()->set('mollie-billing.company_name', null);
    expect(operationsConfigLayout('admin'))->toContain(e(__('billing::admin.title', ['app' => 'Fallback App'])));
});

// ── Invoices ────────────────────────────────────────────────────────────────

it('invoices.disk and invoices.path — decide where an invoice PDF is written', function (): void {
    Storage::fake('archive');
    config()->set('mollie-billing.invoices.disk', 'archive');
    config()->set('mollie-billing.invoices.path', 'books/2026');

    $billable = operationsConfigBillable();
    $invoice = operationsConfigInvoice($billable);

    expect(app(InvoiceService::class)->regeneratePdf($invoice))->toBeTrue();

    $invoice->refresh();

    expect($invoice->pdf_disk)->toBe('archive')
        ->and($invoice->pdf_path)->toStartWith('books/2026/')
        ->and(Storage::disk('archive')->exists($invoice->pdf_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('invoices.seller — is the seller printed on the document', function (): void {
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'Graystack IT GmbH',
        'tax_number' => 'ATU12345678',
        'email' => 'billing@graystack.test',
        'address' => ['street' => 'Ring 1', 'city' => 'Wien', 'postal_code' => '1010', 'country' => 'AT'],
    ]);

    $billable = operationsConfigBillable();
    $probe = new OperationsConfigPdfProbe(app(VatCalculationService::class), app(InvoiceNumberGenerator::class));
    $pdf = $probe->pdfFor(operationsConfigInvoice($billable), $billable);

    expect($pdf->seller->company)->toBe('Graystack IT GmbH')
        // A VAT ID kept in tax_number is printed as the labelled VAT ID, not bare.
        ->and($pdf->seller->fields)->toBe(['VAT ID:' => 'ATU12345678'])
        ->and($pdf->seller->email)->toBe('billing@graystack.test')
        ->and($pdf->seller->address->city)->toBe('Wien');
});

it('invoices.logo — puts the configured logo on the document, and none when unset', function (): void {
    $billable = operationsConfigBillable();
    $invoice = operationsConfigInvoice($billable);
    $probe = new OperationsConfigPdfProbe(app(VatCalculationService::class), app(InvoiceNumberGenerator::class));

    config()->set('mollie-billing.invoices.logo', null);
    expect($probe->pdfFor($invoice, $billable)->logo)->toBeNull();

    $dataUri = 'data:image/png;base64,iVBORw0KGgo=';
    config()->set('mollie-billing.invoices.logo', $dataUri);
    expect($probe->pdfFor($invoice, $billable)->logo)->toBe($dataUri);
});

it('invoices.serial_number — format and prefixes shape the number an invoice gets', function (): void {
    config()->set('mollie-billing.invoices.serial_number', [
        'format' => 'PPP/YY/CCCC',
        'prefix' => ['invoice' => 'RE', 'refund' => 'GS'],
    ]);

    $year = BillingTime::nowUtc()->format('y');
    $generator = app(InvoiceNumberGenerator::class);

    expect($generator->generate('subscription'))->toBe("RE /{$year}/0001")
        ->and($generator->generate('refund'))->toStartWith('GS ');
});

it('invoices.temporary_url_expiry — is how long a signed download link of a remote disk lives', function (): void {
    $billable = operationsConfigBillable();
    $invoice = operationsConfigInvoice($billable);

    Storage::fake('remote');
    config()->set('filesystems.disks.remote.driver', 's3');
    Storage::disk('remote')->put('books/doc.pdf', '%PDF-1.4');
    $invoice->forceFill(['pdf_disk' => 'remote', 'pdf_path' => 'books/doc.pdf'])->save();

    $expiries = [];
    Storage::disk('remote')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use (&$expiries): string {
        $expiries[] = (int) round(BillingTime::nowUtc()->diffInMinutes($expiration));

        return 'https://bucket.example.test/'.$path;
    });

    \GraystackIT\MollieBilling\MollieBilling::resolveBillableUsing(fn () => $billable);
    \GraystackIT\MollieBilling\MollieBilling::authUsing(fn (): bool => true);

    foreach ([5, 45] as $minutes) {
        config()->set('mollie-billing.invoices.temporary_url_expiry', $minutes);

        $this->get(route('billing.invoice.download', ['invoice' => $invoice->id]))
            ->assertRedirect('https://bucket.example.test/books/doc.pdf');
    }

    expect($expiries)->toBe([5, 45]);
});

// ── OSS ─────────────────────────────────────────────────────────────────────

it('oss.disk and oss.path — decide where an export is written, falling back to the invoice disk', function (): void {
    Storage::fake('tax');
    $service = app(OssProtocolService::class);

    config()->set('mollie-billing.oss.disk', null);
    expect($service->disk())->toBe('local');

    config()->set('mollie-billing.oss.disk', 'tax');
    config()->set('mollie-billing.oss.path', 'vat/oss');

    $export = BillingOssExport::create(['year' => 2026, 'status' => OssExportStatus::Queued]);
    (new \GraystackIT\MollieBilling\Jobs\GenerateOssExportJob($export->id))->handle($service);

    $export->refresh();

    expect($export->status)->toBe(OssExportStatus::Ready)
        ->and($export->disk)->toBe('tax')
        ->and($export->path)->toStartWith('vat/oss/oss-export-2026-')
        ->and(Storage::disk('tax')->exists($export->path))->toBeTrue();
});

it('oss.temporary_url_expiry — is how long a signed export link of a remote disk lives', function (): void {
    Storage::fake('remote');
    config()->set('filesystems.disks.remote.driver', 's3');
    Storage::disk('remote')->put('oss/2026.csv', 'a;b');

    $export = BillingOssExport::create([
        'year' => 2026, 'status' => OssExportStatus::Ready, 'disk' => 'remote', 'path' => 'oss/2026.csv',
    ]);

    $expiries = [];
    Storage::disk('remote')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use (&$expiries): string {
        $expiries[] = (int) round(BillingTime::nowUtc()->diffInMinutes($expiration));

        return 'https://bucket.example.test/'.$path;
    });

    foreach ([10, 90] as $minutes) {
        config()->set('mollie-billing.oss.temporary_url_expiry', $minutes);

        expect(app(OssExportDownloadController::class)(Request::create('/x'), $export))->toBeInstanceOf(RedirectResponse::class);
    }

    expect($expiries)->toBe([10, 90]);
});

// ── Usage ───────────────────────────────────────────────────────────────────

it('allow_overage_default — decides for a billable that has not chosen for itself', function (): void {
    $billable = operationsConfigBillable(['allows_billing_overage' => null]);

    config()->set('mollie-billing.allow_overage_default', true);
    expect($billable->allowsBillingOverage())->toBeTrue();

    config()->set('mollie-billing.allow_overage_default', false);
    expect($billable->allowsBillingOverage())->toBeFalse();

    // The billable's own choice wins over the default, both ways.
    $billable->forceFill(['allows_billing_overage' => true])->save();
    expect($billable->refresh()->allowsBillingOverage())->toBeTrue();
});

it('usage_threshold_percent — is where the usage meter turns to its warning colour', function (): void {
    $meter = fn (): array => \Livewire\Livewire::test('mollie-billing::components.usage-meter', [
        'type' => 'Tokens', 'included' => 100, 'balance' => 40, 'purchasedBalance' => 0, 'trial' => false,
    ])->instance()->data();

    // 60 of 100 used.
    config()->set('mollie-billing.usage_threshold_percent', 80);
    expect($meter()['isWarning'])->toBeFalse()->and($meter()['planUsedColor'])->toBe('bg-emerald-500');

    config()->set('mollie-billing.usage_threshold_percent', 50);
    expect($meter()['isWarning'])->toBeTrue()->and($meter()['planUsedColor'])->toBe('bg-amber-500');
});

// ── Audit ───────────────────────────────────────────────────────────────────

it('audit.log_name — is the log the trail is written to and read from', function (): void {
    config()->set('mollie-billing.audit.log_name', 'ledger');

    $billable = operationsConfigBillable();
    event(new SubscriptionCancelled($billable, false, null));

    $row = BillingAuditEntry::model()::query()->latest('id')->first();

    expect($row->log_name)->toBe('ledger')
        ->and($billable->billingAuditTrail()->count())->toBe(1);

    // Read under another name, the same row is somebody else's.
    config()->set('mollie-billing.audit.log_name', 'billing');
    expect($billable->billingAuditTrail()->count())->toBe(0);
});

// ── Schedule ────────────────────────────────────────────────────────────────

/** The cron expression the package schedules a job with, under the current config. */
function operationsConfigCronFor(string $jobClass): ?string
{
    app()->forgetInstance(Schedule::class);

    foreach (app(Schedule::class)->events() as $event) {
        if (($event->description ?? '') === $jobClass) {
            return $event->expression;
        }
    }

    return null;
}

it('overage_job_time — is when the nightly overage pass is scheduled', function (): void {
    config()->set('mollie-billing.overage_job_time', '02:00');
    expect(operationsConfigCronFor(PrepareUsageOverageJob::class))->toBe('0 2 * * *');

    config()->set('mollie-billing.overage_job_time', '23:40');
    expect(operationsConfigCronFor(PrepareUsageOverageJob::class))->toBe('40 23 * * *');
});

it('cleanup.cron_expression and cleanup.enabled — schedule the orphan cleanup, or do not', function (): void {
    config()->set('mollie-billing.cleanup.enabled', true);
    config()->set('mollie-billing.cleanup.cron_expression', '7 */6 * * *');
    expect(operationsConfigCronFor(CleanupOrphanedBillablesJob::class))->toBe('7 */6 * * *');

    config()->set('mollie-billing.cleanup.enabled', false);
    expect(operationsConfigCronFor(CleanupOrphanedBillablesJob::class))->toBeNull();
});

// ── Admin ───────────────────────────────────────────────────────────────────

it('admin_kpi_cache_ttl — is how long a dashboard figure is served from the cache', function (): void {
    Cache::flush();
    $billable = operationsConfigBillable();
    $start = BillingTime::nowUtc();

    $invoice = fn (): BillingInvoice => operationsConfigInvoice($billable, [
        'period_start' => $start->copy()->subDays(2),
        'period_end' => $start->copy()->addDays(28),
    ]);

    config()->set('mollie-billing.admin_kpi_cache_ttl', 600);
    $invoice();
    expect(app(AdminKpiService::class)->mrr())->toBe(1000);

    // A second invoice: not visible while the first answer is cached …
    $invoice();
    expect(app(AdminKpiService::class)->mrr())->toBe(1000);

    // … and visible once the ten minutes are over.
    $this->travel(601)->seconds();
    expect(app(AdminKpiService::class)->mrr())->toBe(2000);

    // With a short TTL the third invoice shows after that much shorter wait.
    config()->set('mollie-billing.admin_kpi_cache_ttl', 5);
    Cache::flush();
    expect(app(AdminKpiService::class)->mrr())->toBe(2000);
    $invoice();
    $this->travel(6)->seconds();
    expect(app(AdminKpiService::class)->mrr())->toBe(3000);
});

it('billable_model — is the model the nightly pass walks, and without one it walks nothing', function (): void {
    $ended = operationsConfigBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    config()->set('mollie-billing.billable_model', null);
    PrepareUsageOverageJob::dispatchSync();
    expect($ended->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);

    config()->set('mollie-billing.billable_model', TestBillable::class);
    PrepareUsageOverageJob::dispatchSync();
    expect($ended->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
});
