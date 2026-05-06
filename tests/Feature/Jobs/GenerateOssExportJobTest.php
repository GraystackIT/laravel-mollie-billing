<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Jobs\GenerateOssExportJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.oss.disk', 'local');
    config()->set('mollie-billing.oss.path', 'billing/oss-exports');
});

it('generates an OSS export file and marks the row as ready', function (): void {
    $billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 'tester@example.com',
        'billing_country' => 'AT',
    ]);

    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_oss_'.uniqid(),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro Plan',
            'quantity' => 1,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
        ]],
        'created_at' => CarbonImmutable::create(2025, 6, 15, 12, 0, 0, 'UTC'),
        'updated_at' => CarbonImmutable::create(2025, 6, 15, 12, 0, 0, 'UTC'),
    ]);

    $export = BillingOssExport::create([
        'year' => 2025,
        'status' => OssExportStatus::Queued,
    ]);

    (new GenerateOssExportJob($export->id))->handle(app(OssProtocolService::class));

    $export->refresh();

    expect($export->status)->toBe(OssExportStatus::Ready);
    expect($export->disk)->toBe('local');
    expect($export->path)->toContain('billing/oss-exports/oss-export-2025-');
    expect($export->path)->toEndWith('.csv');
    expect($export->bytes)->toBeGreaterThan(0);
    expect($export->rows_count)->toBe(1);
    expect($export->completed_at)->not->toBeNull();

    expect(Storage::disk('local')->exists((string) $export->path))->toBeTrue();
});

it('marks the row as failed when the service throws', function (): void {
    $export = BillingOssExport::create([
        'year' => 2025,
        'status' => OssExportStatus::Queued,
    ]);

    $service = new class extends OssProtocolService {
        public function export(int $year): \GraystackIT\MollieBilling\Services\Vat\OssExportResult
        {
            throw new \RuntimeException('boom');
        }
    };

    (new GenerateOssExportJob($export->id))->handle($service);

    $export->refresh();

    expect($export->status)->toBe(OssExportStatus::Failed);
    expect($export->failure_reason)->toBe('boom');
    expect($export->completed_at)->not->toBeNull();
    expect($export->disk)->toBeNull();
});

it('skips processing when status is not queued (idempotent)', function (): void {
    $export = BillingOssExport::create([
        'year' => 2025,
        'status' => OssExportStatus::Ready,
        'disk' => 'local',
        'path' => 'billing/oss-exports/oss-export-2025-existing.csv',
        'bytes' => 42,
        'rows_count' => 5,
        'completed_at' => CarbonImmutable::now('UTC'),
    ]);

    (new GenerateOssExportJob($export->id))->handle(app(OssProtocolService::class));

    $export->refresh();

    expect($export->status)->toBe(OssExportStatus::Ready);
    expect($export->path)->toBe('billing/oss-exports/oss-export-2025-existing.csv');
});
