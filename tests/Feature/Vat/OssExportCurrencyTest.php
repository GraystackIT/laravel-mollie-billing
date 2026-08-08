<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.oss.disk', 'local');
    config()->set('mollie-billing.oss.path', 'billing/oss-exports');

    $this->billable = TestBillable::create([
        'name' => 'Tester',
        'email' => 'tester@example.com',
        'billing_country' => 'AT',
    ]);
});

function ossInvoice(
    TestBillable $billable,
    string $country,
    string $currency,
    int $net,
    float $rate,
    InvoiceKind $kind = InvoiceKind::Subscription,
    int $month = 2,
): BillingInvoice {
    $vat = (int) round($net * $rate / 100);
    $createdAt = CarbonImmutable::create(2026, $month, 15, 12, 0, 0, 'UTC');

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_oss_'.uniqid(),
        'serial_number' => 'OSS-'.uniqid(),
        'invoice_kind' => $kind,
        'status' => InvoiceStatus::Paid,
        'country' => $country,
        'currency' => $currency,
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro Plan',
            'quantity' => 1,
            'amount_net' => $net,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/** @return array<int, array<int, string>> */
function readOssCsv(string $path): array
{
    $raw = trim((string) Storage::disk('local')->get($path));

    return array_map('str_getcsv', explode("\n", $raw));
}

it('groups amounts by currency instead of summing unlike currencies', function (): void {
    // Same quarter, same country, same rate — but the merchant switched
    // BILLING_CURRENCY mid-year. Summing these into one `_eur` column would
    // produce a filing built on nonsense numbers.
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    ossInvoice($this->billable, 'AT', 'CHF', 5000, 20.0);

    $result = (new OssProtocolService())->export(2026);

    expect($result->rows)->toBe(2);
    expect($result->currencies)->toBe(['CHF', 'EUR']);

    $rows = readOssCsv($result->path);

    expect($rows[0])->toBe(['quarter', 'currency', 'country', 'sales_count', 'net_amount', 'vat_amount', 'vat_rate']);

    $byCurrency = [];
    foreach (array_slice($rows, 1) as $row) {
        $byCurrency[$row[1]] = $row;
    }

    expect($byCurrency['EUR'][4])->toBe('10.00');
    expect($byCurrency['EUR'][5])->toBe('2.00');
    expect($byCurrency['CHF'][4])->toBe('50.00');
    expect($byCurrency['CHF'][5])->toBe('10.00');
});

it('writes each row at its own currency scale', function (): void {
    // A JPY sale and a EUR sale in the same quarter. The number of decimals is a
    // property of the currency the column beside it declares, so a hardcoded /100
    // exported ¥3000 as "30.00" — an OSS protocol is a tax filing, and that is off by
    // two orders of magnitude. KWD (three decimals) was wrong by 10× the other way.
    ossInvoice($this->billable, 'JP', 'JPY', 3000, 10.0);
    ossInvoice($this->billable, 'AT', 'EUR', 2000, 20.0);
    ossInvoice($this->billable, 'KW', 'KWD', 1500, 0.0);

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    $byCurrency = [];
    foreach (array_slice($rows, 1) as $row) {
        $byCurrency[$row[1]] = $row;
    }

    expect($byCurrency['JPY'][4])->toBe('3000')
        ->and($byCurrency['JPY'][5])->toBe('300')
        ->and($byCurrency['EUR'][4])->toBe('20.00')
        ->and($byCurrency['EUR'][5])->toBe('4.00')
        ->and($byCurrency['KWD'][4])->toBe('1.500');
});

it('labels the amount columns with the currency for a single-currency export', function (): void {
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    expect($rows[0])->toBe(['quarter', 'currency', 'country', 'sales_count', 'net_amount_eur', 'vat_amount_eur', 'vat_rate']);
    expect($result->currencies)->toBe(['EUR']);
});

it('does not count credit notes as sales', function (): void {
    // Ten sales and three full refunds are ten sales, not thirteen.
    for ($i = 0; $i < 10; $i++) {
        ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    }
    for ($i = 0; $i < 3; $i++) {
        ossInvoice($this->billable, 'AT', 'EUR', -1000, 20.0, InvoiceKind::Refund);
    }

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    expect($result->rows)->toBe(1);
    expect($rows[1][3])->toBe('10');            // sales_count
    expect($rows[1][4])->toBe('70.00');         // 10 × 10.00 − 3 × 10.00
    expect($rows[1][5])->toBe('14.00');         // 10 × 2.00 − 3 × 2.00
});

it('keeps the refunded amounts netting out while excluding them from the count', function (): void {
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    ossInvoice($this->billable, 'AT', 'EUR', -1000, 20.0, InvoiceKind::Refund);

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    expect($rows[1][3])->toBe('1');
    expect($rows[1][4])->toBe('0.00');
    expect($rows[1][5])->toBe('0.00');
});

it('buckets per quarter, currency, country and rate', function (): void {
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0, month: 2);   // Q1
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0, month: 5);   // Q2
    ossInvoice($this->billable, 'DE', 'EUR', 1000, 19.0, month: 2);   // Q1, other country
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 10.0, month: 2);   // Q1, reduced rate

    $result = (new OssProtocolService())->export(2026);

    expect($result->rows)->toBe(4);
});

it('falls back to the configured currency for rows without one', function (): void {
    config()->set('mollie-billing.currency', 'EUR');

    $invoice = ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    // `currency` is NOT NULL, so a legacy row can only be blank, never null.
    BillingInvoice::query()->whereKey($invoice->id)->update(['currency' => '']);

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    expect($rows[1][1])->toBe('EUR');
});
