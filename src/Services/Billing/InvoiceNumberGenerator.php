<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
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
     * Counter resets per year.
     *
     * The number is only unique if the row that consumes it is inserted inside
     * the same transaction: the lock taken while reading the highest serial is
     * released on commit. Callers therefore open a transaction around
     * generate() + save() (see InvoiceService::persistWithSerial()) and this
     * method joins it rather than committing a nested transaction of its own.
     */
    public function generate(string $invoiceKind): string
    {
        $format = (string) config('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC');
        $prefixes = (array) config('mollie-billing.invoices.serial_number.prefix', [
            'invoice' => 'IN',
            'credit_note' => 'CR',
            'refund' => 'CR',
        ]);

        // Refunds and credit notes share the CR prefix; every other kind uses the
        // 'invoice' prefix.
        $prefix = match ($invoiceKind) {
            'refund', 'credit_note' => $prefixes['refund'] ?? $prefixes['credit_note'] ?? 'CR',
            default => $prefixes[$invoiceKind] ?? $prefixes['invoice'] ?? 'IN',
        };
        // UTC, like every other timestamp in the package. `date('y')` reads the server
        // timezone, so a serial minted in the hours around New Year could carry the
        // neighbouring year's digits while `created_at` — which the OSS export and the
        // invoice PDF both use — said otherwise. Sequence integrity was never at risk;
        // the document just contradicted itself.
        $year = (int) BillingTime::nowUtc()->format('y');

        $allocate = function () use ($format, $prefix, $year): string {
            $prefixLen = substr_count($format, 'P');
            $yearLen = substr_count($format, 'Y');
            $counterLen = substr_count($format, 'C');

            // Truncated as well as padded. `extractCounter()` reads the counter at a fixed
            // offset computed from the *format*, so a prefix longer than its `P` run made
            // writer and reader disagree: with the default `PP-YYCCCCCC` and prefix `INV`,
            // the serials converged on IN V-25555556 and then repeated — the unique index
            // rejected it, persistWithSerial() retried three identical times and rethrew, so
            // every invoice from that point on failed for money Mollie had already collected.
            $prefixStr = str_pad(substr($prefix, 0, max(0, $prefixLen)), $prefixLen, ' ', STR_PAD_RIGHT);
            $yearStr = str_pad((string) $year, $yearLen, '0', STR_PAD_LEFT);

            // A bounded range rather than LIKE 'IN-25______'. The counter slot is
            // fixed-width and zero-padded, so lexicographic order equals numeric
            // order and these two bounds are exact for any format
            // buildSerialNumber() can emit.
            //
            // The point is the lock. serial_number is uniquely indexed, so the
            // range scan locks the one index record it returns; a LIKE with a
            // leading literal can still use the index on SQLite and MySQL, but on
            // Postgres under a non-C collation it cannot, and FOR UPDATE then
            // degenerates into locking every row it scanned — every invoice of the
            // year, held until the caller's transaction commits. That is what let
            // an admin-triggered refund and a renewal webhook block each other.
            // The IN- and CR- ranges are also disjoint here, so those two paths no
            // longer contend at all.
            $rangeStart = $this->buildSerialNumber($format, $prefixStr, $yearStr, str_repeat('0', $counterLen));
            $rangeEnd = $this->buildSerialNumber($format, $prefixStr, $yearStr, str_repeat('9', $counterLen));

            $latest = BillingInvoice::query()
                ->where('serial_number', '>=', $rangeStart)
                ->where('serial_number', '<=', $rangeEnd)
                ->orderByDesc('serial_number')
                ->lockForUpdate()
                ->value('serial_number');

            $nextCount = 1;

            if ($latest !== null) {
                $currentCount = $this->extractCounter($format, (string) $latest);
                $nextCount = $currentCount + 1;
            }

            $counterStr = str_pad((string) $nextCount, $counterLen, '0', STR_PAD_LEFT);

            // Past the slot width str_pad stops padding and the serial grows a
            // digit, which sorts *below* the upper bound instead of above it — the
            // overflowed row becomes invisible to the query above, the counter
            // silently restarts at 1 and the unique index rejects the invoice after
            // the payment has already been taken. Fail loudly while nothing is lost.
            if (strlen($counterStr) > $counterLen) {
                throw new \RuntimeException(sprintf(
                    'Invoice serial counter overflowed the %d-digit slot of format "%s" for %s%s. Widen mollie-billing.invoices.serial_number.format.',
                    $counterLen,
                    $format,
                    trim($prefixStr),
                    $yearStr,
                ));
            }

            return $this->buildSerialNumber($format, $prefixStr, $yearStr, $counterStr);
        };

        return DB::transactionLevel() > 0 ? $allocate() : DB::transaction($allocate);
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
