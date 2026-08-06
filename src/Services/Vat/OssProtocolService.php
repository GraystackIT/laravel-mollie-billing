<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
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
     * Columns: quarter, currency, country, sales_count, net_amount[_cur], vat_amount[_cur], vat_rate
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
            ->select(['id', 'country', 'currency', 'invoice_kind', 'line_items', 'created_at'])
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
            ]);

            foreach ($buckets as $row) {
                fputcsv($tmp, [
                    $row['quarter'],
                    $row['currency'],
                    $row['country'],
                    $row['sales_count'],
                    number_format($row['net_amount_cents'] / 100, 2, '.', ''),
                    number_format($row['vat_amount_cents'] / 100, 2, '.', ''),
                    $row['vat_rate'],
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
     * @param  array<string, array{quarter: int, currency: string, country: string, sales_count: int, net_amount_cents: int, vat_amount_cents: int, vat_rate: string}>  $buckets
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
            $key = "{$quarter}|{$currency}|{$country}|{$rate}";

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'quarter' => $quarter,
                    'currency' => $currency,
                    'country' => $country,
                    'sales_count' => 0,
                    'net_amount_cents' => 0,
                    'vat_amount_cents' => 0,
                    'vat_rate' => $rate,
                ];
            }

            if ($isSale) {
                $buckets[$key]['sales_count']++;
            }
            $buckets[$key]['net_amount_cents'] += $totals['net'];
            $buckets[$key]['vat_amount_cents'] += $totals['vat'];
        }
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
