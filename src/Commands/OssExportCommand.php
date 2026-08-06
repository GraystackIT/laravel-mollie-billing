<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class OssExportCommand extends Command
{
    protected $signature = 'billing:oss-export {year}';

    protected $description = 'Export the OSS (One-Stop Shop) VAT protocol for the given year.';

    public function handle(OssProtocolService $service): int
    {
        $raw = (string) $this->argument('year');

        // Same bounds the admin UI enforces. Without them `(int) 'foo'` produced a
        // year=0 row with an empty CSV reported as SUCCESS, and a negative year threw
        // an unhandled QueryException against the unsignedSmallInteger column —
        // outside the try/catch, so no Failed row was recorded either.
        if (! preg_match('/^\d{4}$/', $raw) || (int) $raw < 2000 || (int) $raw > 2999) {
            $this->error("Invalid year [{$raw}] — expected a four-digit year between 2000 and 2999.");

            return self::FAILURE;
        }

        $year = (int) $raw;

        try {
            $export = BillingOssExport::create([
                'year' => $year,
                'status' => OssExportStatus::Processing,
            ]);
        } catch (Throwable $e) {
            $this->error("Could not record the export run: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $result = $service->export($year);
        } catch (Throwable $e) {
            $export->update([
                'status' => OssExportStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'completed_at' => Carbon::now('UTC'),
            ]);

            $this->error("OSS export failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $export->update([
            'status' => OssExportStatus::Ready,
            'disk' => $result->disk,
            'path' => $result->path,
            'bytes' => $result->bytes,
            'rows_count' => $result->rows,
            'completed_at' => Carbon::now('UTC'),
        ]);

        $this->info("OSS export written to disk [{$result->disk}] at {$result->path} ({$result->rows} rows, {$result->bytes} bytes).");

        return self::SUCCESS;
    }
}
