<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class OssProtocolService
{
    /**
     * Generate an OSS quarterly export CSV for the given calendar year and
     * write it to the configured filesystem disk (S3-compatible or local).
     *
     * Aggregiert pro line_item nach (quarter, country, vat_rate). Eine Multi-VAT-Invoice
     * trägt zu mehreren Buckets bei (eine pro line_item.vat_rate).
     *
     * Columns: quarter, country, sales_count, net_amount_eur, vat_amount_eur, vat_rate
     */
    public function export(int $year): OssExportResult
    {
        // Quartalsabgrenzung in UTC, damit der Export deterministisch ist und
        // nicht von app.timezone abhängt (ein Datensatz vom 31.12. 23:30 UTC
        // soll immer in Q4 des Vorjahres landen, nicht in Q1 des Folgejahres).
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'UTC');
        $end = Carbon::create($year, 12, 31, 23, 59, 59, 'UTC');

        $buckets = [];

        BillingInvoice::query()
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'country', 'line_items', 'created_at'])
            ->chunkById(500, function ($invoices) use (&$buckets): void {
                foreach ($invoices as $invoice) {
                    $this->collectBuckets($buckets, $invoice);
                }
            });

        // Sort by quarter, country, rate for stable output.
        ksort($buckets);

        $disk = $this->disk();
        $path = $this->buildPath($year);
        $storage = Storage::disk($disk);

        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temporary file for OSS export.');
        }

        try {
            fputcsv($tmp, ['quarter', 'country', 'sales_count', 'net_amount_eur', 'vat_amount_eur', 'vat_rate']);

            foreach ($buckets as $row) {
                fputcsv($tmp, [
                    $row['quarter'],
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
        );
    }

    /**
     * @param  array<string, array{quarter: int, country: string, sales_count: int, net_amount_cents: int, vat_amount_cents: int, vat_rate: string}>  $buckets
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

        // Sammle Lines nach VAT-Rate, damit eine Invoice pro Rate genau einmal als sale zählt.
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
            $key = "{$quarter}|{$country}|{$rate}";

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'quarter' => $quarter,
                    'country' => $country,
                    'sales_count' => 0,
                    'net_amount_cents' => 0,
                    'vat_amount_cents' => 0,
                    'vat_rate' => $rate,
                ];
            }

            $buckets[$key]['sales_count']++;
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
        // Timestamp im Pfad behält frühere Exporte als Audit-Spur.
        $stamp = Carbon::now('UTC')->format('Ymd-His');

        return "{$base}/oss-export-{$year}-{$stamp}.csv";
    }
}
