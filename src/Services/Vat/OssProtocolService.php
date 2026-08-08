<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class OssProtocolService
{
    /**
     * Generate an OSS quarterly export CSV for the given calendar year and
     * write it to the configured filesystem disk (S3-compatible or local).
     *
     * Aggregates per line_item by (quarter, currency, country, vat_rate). A
     * multi-VAT invoice contributes to multiple buckets (one per
     * line_item.vat_rate).
     *
     * Currency is part of the bucket key, never assumed: a dataset spanning a
     * `BILLING_CURRENCY` change would otherwise sum unlike amounts into one
     * column. The amount columns carry the currency in their name while the
     * export is single-currency (`net_amount_eur`) and drop the suffix as soon
     * as more than one currency is present — the `currency` column is
     * authoritative either way.
     *
     * `sales_count` counts sales only. Credit notes (`InvoiceKind::Refund`)
     * still contribute their negative net/VAT so the totals net out, but they
     * are not sales and must not inflate the count.
     *
     * `scope` says which filing each row belongs to — only `oss` rows belong in the OSS
     * return. See {@see scopeFor()}.
     *
     * Columns: quarter, currency, country, sales_count, net_amount[_cur], vat_amount[_cur], vat_rate, scope
     */
    public function export(int $year): OssExportResult
    {
        // Quarter boundaries in UTC so the export is deterministic and
        // doesn't depend on app.timezone (a record from 31 Dec 23:30 UTC
        // should always land in Q4 of that year, not Q1 of the following year).
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'UTC');
        $end = Carbon::create($year, 12, 31, 23, 59, 59, 'UTC');

        $buckets = [];

        BillingInvoice::query()
            ->whereBetween('created_at', [$start, $end])
            // `vat_validation_id` is selected and the relation eager-loaded because
            // scopeFor() classifies on it. Left out of the select it reads as null on
            // every row, and every reverse-charged B2B supply would be filed as OSS.
            ->select(['id', 'country', 'currency', 'invoice_kind', 'line_items', 'created_at', 'vat_validation_id'])
            ->with('vatValidation')
            ->chunkById(500, function ($invoices) use (&$buckets): void {
                foreach ($invoices as $invoice) {
                    $this->collectBuckets($buckets, $invoice);
                }
            });

        // Sort by quarter, currency, country, rate for stable output.
        ksort($buckets);

        $currencies = array_values(array_unique(array_column($buckets, 'currency')));
        sort($currencies);

        $disk = $this->disk();
        $path = $this->buildPath($year);
        $storage = Storage::disk($disk);

        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temporary file for OSS export.');
        }

        try {
            $suffix = count($currencies) === 1 ? '_'.strtolower($currencies[0]) : '';
            fputcsv($tmp, [
                'quarter',
                'currency',
                'country',
                'sales_count',
                'net_amount'.$suffix,
                'vat_amount'.$suffix,
                'vat_rate',
                // Trailing, so the existing column positions are unchanged for anything
                // already reading this file. See scopeFor(): only `oss` rows belong in
                // the OSS return.
                'scope',
            ]);

            foreach ($buckets as $row) {
                fputcsv($tmp, [
                    $row['quarter'],
                    $row['currency'],
                    $row['country'],
                    $row['sales_count'],
                    // Scaled by the row's own currency, which the bucket key already
                    // carries and which the column beside it declares. A hardcoded /100
                    // exported JPY at 1/100 and KWD at 10× — an OSS protocol is a tax
                    // filing, so the magnitude has to be right for every currency the
                    // install accepts, not just the two-decimal ones.
                    BillingMoney::mollieAmount((int) $row['net_amount_cents'], $row['currency']),
                    BillingMoney::mollieAmount((int) $row['vat_amount_cents'], $row['currency']),
                    $row['vat_rate'],
                    $row['scope'],
                ]);
            }

            $bytes = (int) ftell($tmp);
            rewind($tmp);

            // Stream the file onto the disk so S3-compatible drivers can upload
            // without holding the full payload in memory.
            $written = $storage->writeStream($path, $tmp);
            if ($written === false) {
                throw new \RuntimeException("Failed to write OSS export to disk [{$disk}] at {$path}.");
            }
        } finally {
            // tmpfile() handles unlink on close, but writeStream() may have
            // already closed the stream; guard against double-fclose.
            if (is_resource($tmp)) {
                fclose($tmp);
            }
        }

        return new OssExportResult(
            disk: $disk,
            path: $path,
            bytes: $bytes,
            rows: count($buckets),
            currencies: $currencies,
        );
    }

    /**
     * @param  array<string, array{quarter: int, currency: string, country: string, sales_count: int, net_amount_cents: int, vat_amount_cents: int, vat_rate: string, scope: string}>  $buckets
     */
    private function collectBuckets(array &$buckets, BillingInvoice $invoice): void
    {
        // BillingInvoice::created_at uses the UtcDatetime cast and is always
        // a UTC CarbonImmutable; the CarbonInterface branch covers that path.
        // The string fallback exists only for raw rows that bypass the model cast
        // and parses strictly as UTC so app.timezone cannot leak in.
        $createdAt = $invoice->created_at instanceof CarbonInterface
            ? Carbon::instance($invoice->created_at)->setTimezone('UTC')
            : Carbon::createFromFormat('Y-m-d H:i:s', (string) $invoice->created_at, 'UTC');
        $quarter = (int) ceil(((int) $createdAt->month) / 3);
        $country = strtoupper((string) $invoice->country);
        $currency = strtoupper((string) ($invoice->currency ?: config('mollie-billing.currency', 'EUR')));
        $isSale = $invoice->invoice_kind !== InvoiceKind::Refund;
        $scope = $this->scopeFor($invoice, $country);

        // Collect lines by VAT rate so an invoice counts exactly once as a sale per rate.
        $linesByRate = [];
        foreach ((array) ($invoice->line_items ?? []) as $line) {
            $rate = number_format((float) ($line['vat_rate'] ?? 0), 2, '.', '');
            if (! isset($linesByRate[$rate])) {
                $linesByRate[$rate] = ['net' => 0, 'vat' => 0];
            }
            $linesByRate[$rate]['net'] += (int) ($line['amount_net'] ?? 0);
            $linesByRate[$rate]['vat'] += (int) ($line['vat_amount'] ?? 0);
        }

        foreach ($linesByRate as $rate => $totals) {
            // `scope` is part of the key, so the three filings never merge into one
            // bucket even where quarter, currency, country and rate coincide.
            $key = "{$quarter}|{$currency}|{$country}|{$rate}|{$scope}";

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'quarter' => $quarter,
                    'currency' => $currency,
                    'country' => $country,
                    'sales_count' => 0,
                    'net_amount_cents' => 0,
                    'vat_amount_cents' => 0,
                    'vat_rate' => $rate,
                    'scope' => $scope,
                ];
            }

            if ($isSale) {
                $buckets[$key]['sales_count']++;
            }
            $buckets[$key]['net_amount_cents'] += $totals['net'];
            $buckets[$key]['vat_amount_cents'] += $totals['vat'];
        }
    }

    /**
     * Which filing a supply belongs to.
     *
     * OSS covers cross-border **B2C** supplies inside the EU. Three kinds of row do not
     * belong in it and used to be aggregated into the OSS buckets unmarked, so a CSV
     * filed verbatim over-declared OSS turnover:
     *
     *  - `reverse_charge` — a validated EU business customer. Zero-rated here and
     *    declared by the customer; it belongs in the EC Sales List.
     *  - `domestic` — the customer is in the seller's own country. Domestic VAT,
     *    declared in the ordinary national return.
     *  - `non_eu` — outside the EU altogether. Outside the scope of OSS.
     *
     * Marked rather than dropped: turnover that silently vanishes from an export is
     * harder to notice than turnover in a column that says what it is. When the seller
     * country is not configured, `domestic` cannot be decided at all — those rows stay
     * `oss`, which is the pre-existing behaviour, and `sellerCountry()`'s own warning
     * covers the misconfiguration.
     */
    private function scopeFor(BillingInvoice $invoice, string $country): string
    {
        if ($country !== '' && ! in_array($country, CountryResolver::euCountryCodes(), true)) {
            return 'non_eu';
        }

        $seller = app(VatCalculationService::class)->sellerCountry();
        $isDomestic = $seller !== null && $country !== '' && strtoupper($seller) === $country;

        // Domestic is decided first. A validated VAT number alone does not make a supply
        // reverse-charged: `VatCalculationService::reverseChargeApplies()` — the rule the
        // charge itself used — also requires the customer country to differ from the
        // seller's, so a domestic business customer pays full domestic VAT. Asking about
        // the validation first labelled those rows `reverse_charge`, which sent real
        // domestic turnover and its collected VAT into the EC Sales List and out of the
        // national return entirely — with a non-zero VAT amount on a line the list says
        // is zero-rated.
        if ($isDomestic) {
            return 'domestic';
        }

        $validation = $invoice->vatValidation;

        if ($validation !== null && (bool) $validation->valid) {
            return 'reverse_charge';
        }

        return 'oss';
    }

    public function disk(): string
    {
        $disk = config('mollie-billing.oss.disk');
        if (! is_string($disk) || $disk === '') {
            $disk = config('mollie-billing.invoices.disk', 'local');
        }

        return (string) $disk;
    }

    private function buildPath(int $year): string
    {
        $base = trim((string) config('mollie-billing.oss.path', 'billing/oss-exports'), '/');
        // Timestamp in the path preserves earlier exports as an audit trail.
        $stamp = Carbon::now('UTC')->format('Ymd-His');

        return "{$base}/oss-export-{$year}-{$stamp}.csv";
    }
}
