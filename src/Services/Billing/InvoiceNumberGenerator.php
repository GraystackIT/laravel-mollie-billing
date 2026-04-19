<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberGenerator
{
    /**
     * Generate a unique, sequential invoice serial number.
     *
     * The format is read from config (e.g. 'PP-YYCCCCCC'):
     *   P = prefix character (padded to slot length)
     *   Y = year digit (padded to slot length)
     *   C = counter digit (padded to slot length)
     *
     * Counter resets per year. Atomic via DB transaction + lock.
     */
    public function generate(string $invoiceKind): string
    {
        $format = (string) config('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC');
        $prefixes = (array) config('mollie-billing.invoices.serial_number.prefix', [
            'invoice' => 'IN',
            'credit_note' => 'CR',
        ]);

        $prefix = $prefixes[$invoiceKind] ?? $prefixes['invoice'] ?? 'IN';
        $year = (int) date('y'); // 2-digit year

        return DB::transaction(function () use ($format, $prefix, $year): string {
            $prefixLen = substr_count($format, 'P');
            $yearLen = substr_count($format, 'Y');
            $counterLen = substr_count($format, 'C');

            $prefixStr = str_pad($prefix, $prefixLen, ' ', STR_PAD_RIGHT);
            $yearStr = str_pad((string) $year, $yearLen, '0', STR_PAD_LEFT);

            // Build a LIKE pattern to find the latest serial number for this prefix + year.
            $searchPattern = $this->buildSearchPattern($format, $prefixStr, $yearStr);

            $latest = BillingInvoice::where('serial_number', 'LIKE', $searchPattern)
                ->lockForUpdate()
                ->orderByDesc('serial_number')
                ->value('serial_number');

            $nextCount = 1;

            if ($latest !== null) {
                $currentCount = $this->extractCounter($format, (string) $latest);
                $nextCount = $currentCount + 1;
            }

            $counterStr = str_pad((string) $nextCount, $counterLen, '0', STR_PAD_LEFT);

            return $this->buildSerialNumber($format, $prefixStr, $yearStr, $counterStr);
        });
    }

    private function buildSearchPattern(string $format, string $prefix, string $year): string
    {
        $result = '';
        $i = 0;
        $len = strlen($format);

        while ($i < $len) {
            $char = $format[$i];

            if ($char === 'P') {
                $count = $this->countConsecutive($format, $i, 'P');
                $result .= $prefix;
                $i += $count;
            } elseif ($char === 'Y') {
                $count = $this->countConsecutive($format, $i, 'Y');
                $result .= $year;
                $i += $count;
            } elseif ($char === 'C') {
                $count = $this->countConsecutive($format, $i, 'C');
                $result .= str_repeat('_', $count); // SQL single-char wildcard
                $i += $count;
            } else {
                $result .= $char; // literal separator (e.g. '-')
                $i++;
            }
        }

        return $result;
    }

    private function extractCounter(string $format, string $serialNumber): int
    {
        // Find the position and length of the counter (C chars) in the format.
        $counterStart = strpos($format, 'C');
        $counterLen = substr_count($format, 'C');

        if ($counterStart === false || $counterLen === 0) {
            return 0;
        }

        return (int) substr($serialNumber, $counterStart, $counterLen);
    }

    private function buildSerialNumber(string $format, string $prefix, string $year, string $counter): string
    {
        $result = '';
        $i = 0;
        $len = strlen($format);

        while ($i < $len) {
            $char = $format[$i];

            if ($char === 'P') {
                $count = $this->countConsecutive($format, $i, 'P');
                $result .= $prefix;
                $i += $count;
            } elseif ($char === 'Y') {
                $count = $this->countConsecutive($format, $i, 'Y');
                $result .= $year;
                $i += $count;
            } elseif ($char === 'C') {
                $count = $this->countConsecutive($format, $i, 'C');
                $result .= $counter;
                $i += $count;
            } else {
                $result .= $char;
                $i++;
            }
        }

        return $result;
    }

    private function countConsecutive(string $format, int $start, string $char): int
    {
        $count = 0;
        $len = strlen($format);

        for ($i = $start; $i < $len && $format[$i] === $char; $i++) {
            $count++;
        }

        return $count;
    }
}
