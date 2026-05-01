<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Carbon;

class OssProtocolService
{
    /**
     * Generate an OSS quarterly export CSV for the given calendar year.
     *
     * Aggregiert pro line_item nach (quarter, country, vat_rate). Eine Multi-VAT-Invoice
     * trägt zu mehreren Buckets bei (eine pro line_item.vat_rate).
     *
     * Columns: quarter, country, sales_count, net_amount_eur, vat_amount_eur, vat_rate
     *
     * Returns the absolute path to the written CSV file.
     */
    public function export(int $year): string
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0);
        $end = Carbon::create($year, 12, 31, 23, 59, 59);

        $invoices = BillingInvoice::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['country', 'line_items', 'created_at']);

        // Aggregate: quarter|country|vat_rate => [count, net, vat]
        // sales_count zählt einmalig pro Invoice-Bucket-Vorkommen.
        $buckets = [];
        foreach ($invoices as $invoice) {
            $createdAt = $invoice->created_at instanceof Carbon
                ? $invoice->created_at
                : Carbon::parse($invoice->created_at);
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

        // Sort by quarter, country, rate for stable output.
        ksort($buckets);

        $path = storage_path("app/oss-export-{$year}.csv");

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open OSS export file for writing: {$path}");
        }

        try {
            fputcsv($handle, ['quarter', 'country', 'sales_count', 'net_amount_eur', 'vat_amount_eur', 'vat_rate']);

            foreach ($buckets as $row) {
                fputcsv($handle, [
                    $row['quarter'],
                    $row['country'],
                    $row['sales_count'],
                    number_format($row['net_amount_cents'] / 100, 2, '.', ''),
                    number_format($row['vat_amount_cents'] / 100, 2, '.', ''),
                    $row['vat_rate'],
                ]);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }
}
