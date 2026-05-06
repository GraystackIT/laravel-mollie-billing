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
        $year = (int) $this->argument('year');

        $export = BillingOssExport::create([
            'year' => $year,
            'status' => OssExportStatus::Processing,
        ]);

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
