<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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

it('quartile boundaries are computed in UTC regardless of app.timezone', function (): void {
    $previousAppTz = config('app.timezone');
    $previousPhpTz = date_default_timezone_get();

    config()->set('app.timezone', 'Pacific/Auckland');
    date_default_timezone_set('Pacific/Auckland');

    try {
        $billable = TestBillable::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'billing_country' => 'AT',
        ]);

        // 2025-12-31 23:30 UTC = 2026-01-01 12:30 NZ-Zeit.
        // Korrektes Quartal: Q4 2025 (UTC). Falsches Quartal (App-TZ): Q1 2026.
        $createdAt = CarbonImmutable::create(2025, 12, 31, 23, 30, 0, 'UTC');

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
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $service = new OssProtocolService();
        $result = $service->export(2025);

        expect($result->disk)->toBe('local');
        expect($result->rows)->toBe(1);

        $disk = Storage::disk('local');
        expect($disk->exists($result->path))->toBeTrue();

        $rows = array_map('str_getcsv', explode("\n", trim((string) $disk->get($result->path))));
        // Header + 1 data row: invoice fell into Q4 2025 (UTC quarter).
        expect(count($rows))->toBe(2);
        expect($rows[1][0])->toBe('4'); // quarter
        expect($rows[1][1])->toBe('AT'); // country
    } finally {
        config()->set('app.timezone', $previousAppTz);
        date_default_timezone_set($previousPhpTz ?: 'UTC');
    }
});
