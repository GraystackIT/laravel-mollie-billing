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

    expect($rows[0])->toBe(['quarter', 'currency', 'country', 'sales_count', 'net_amount', 'vat_amount', 'vat_rate', 'scope']);

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

    expect($rows[0])->toBe(['quarter', 'currency', 'country', 'sales_count', 'net_amount_eur', 'vat_amount_eur', 'vat_rate', 'scope']);
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

it('marks which filing each row belongs to', function (): void {
    // OSS covers cross-border B2C supplies inside the EU. These four rows used to be
    // aggregated together unmarked, so a CSV filed verbatim over-declared OSS turnover
    // by everything that is not actually OSS.
    config()->set('mollie-billing.vat.seller_country', 'AT');

    // Cross-border EU consumer — the only genuine OSS row.
    ossInvoice($this->billable, 'DE', 'EUR', 1000, 19.0);

    // The seller's own country: domestic VAT, declared in the national return.
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);

    // Outside the EU: outside the scope of OSS entirely.
    ossInvoice($this->billable, 'US', 'EUR', 1000, 0.0);

    // A validated EU business customer: zero-rated here, declared by the customer.
    // It belongs in the EC Sales List — and it shares country and rate with nothing
    // else, so only the validation row tells it apart.
    $b2b = ossInvoice($this->billable, 'FR', 'EUR', 1000, 0.0);
    $validation = new GraystackIT\MollieBilling\Models\BillingVatValidation;
    $validation->billable_type = $this->billable->getMorphClass();
    $validation->billable_id = $this->billable->getKey();
    $validation->vat_number = 'FR12345678901';
    $validation->country_code = 'FR';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();
    $b2b->forceFill(['vat_validation_id' => $validation->getKey()])->save();

    $result = (new OssProtocolService())->export(2026);
    $rows = readOssCsv($result->path);

    $byCountry = [];
    foreach (array_slice($rows, 1) as $row) {
        $byCountry[$row[2]] = $row[7];
    }

    expect($byCountry)->toBe([
        'AT' => 'domestic',
        'DE' => 'oss',
        'FR' => 'reverse_charge',
        'US' => 'non_eu',
    ]);
});

it('calls a domestic business customer domestic, not reverse charge', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');

    // An Austrian business customer of an Austrian seller pays full Austrian VAT —
    // `reverseChargeApplies()` requires the countries to differ, which is the rule that
    // priced this invoice at 20 %. Asking about the validation first labelled the row
    // `reverse_charge`, so €29.00 of turnover and €5.80 of collected Austrian VAT went
    // into the EC Sales List instead of the national return, on a line that list says is
    // zero-rated.
    $invoice = ossInvoice($this->billable, 'AT', 'EUR', 2900, 20.0);

    $validation = new GraystackIT\MollieBilling\Models\BillingVatValidation;
    $validation->billable_type = $this->billable->getMorphClass();
    $validation->billable_id = $this->billable->getKey();
    $validation->vat_number = 'ATU12345678';
    $validation->country_code = 'AT';
    $validation->valid = true;
    $validation->vies_response = [];
    $validation->checked_at = now();
    $validation->save();
    $invoice->forceFill(['vat_validation_id' => $validation->getKey()])->save();

    $rows = readOssCsv((new OssProtocolService())->export(2026)->path);

    expect($rows[1][7])->toBe('domestic')
        ->and($rows[1][5])->toBe('5.80');
});

it('keeps a domestic and a cross-border row apart even at the same rate', function (): void {
    config()->set('mollie-billing.vat.seller_country', 'AT');

    // Austria and Bulgaria both charge 20 %. Without `scope` in the bucket key these
    // two would have merged into one row and the domestic turnover would have been
    // filed as OSS.
    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    ossInvoice($this->billable, 'BG', 'EUR', 1000, 20.0);

    $result = (new OssProtocolService())->export(2026);

    expect($result->rows)->toBe(2);

    $scopes = array_map(fn (array $r) => $r[7], array_slice(readOssCsv($result->path), 1));
    sort($scopes);

    expect($scopes)->toBe(['domestic', 'oss']);
});

it('leaves everything as oss when no seller country is configured', function (): void {
    // `domestic` cannot be decided without one, and inventing an answer would be worse
    // than the pre-existing behaviour. sellerCountry() warns about that separately.
    config()->set('mollie-billing.vat.seller_country', null);
    config()->set('mollie-billing.invoices.seller.address.country', null);

    ossInvoice($this->billable, 'AT', 'EUR', 1000, 20.0);
    ossInvoice($this->billable, 'DE', 'EUR', 1000, 19.0);

    $rows = readOssCsv((new OssProtocolService())->export(2026)->path);

    expect(array_map(fn (array $r) => $r[7], array_slice($rows, 1)))->toBe(['oss', 'oss']);
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
