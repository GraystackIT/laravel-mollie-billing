<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\OssExportStatus;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingOssExport;
use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Generates the OSS quarterly CSV asynchronously and persists the resulting
 * file location on the BillingOssExport row. Marks the row as Failed on
 * unrecoverable errors so the admin UI can surface the reason; queue retries
 * are intentionally disabled — admins click "Regenerate" instead.
 */
class GenerateOssExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $exportId)
    {
        $this->initializeBillingQueue();
    }

    public function handle(OssProtocolService $service): void
    {
        $export = BillingOssExport::query()->whereKey($this->exportId)->first();
        if ($export === null) {
            return;
        }

        if ($export->status !== OssExportStatus::Queued) {
            return;
        }

        $export->update(['status' => OssExportStatus::Processing]);

        try {
            $result = $service->export($export->year);
        } catch (Throwable $e) {
            report($e);
            $export->update([
                'status' => OssExportStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'completed_at' => Carbon::now('UTC'),
            ]);

            return;
        }

        $export->update([
            'status' => OssExportStatus::Ready,
            'disk' => $result->disk,
            'path' => $result->path,
            'bytes' => $result->bytes,
            'rows_count' => $result->rows,
            'completed_at' => Carbon::now('UTC'),
            'failure_reason' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $export = BillingOssExport::query()->whereKey($this->exportId)->first();
        if ($export === null) {
            return;
        }

        if ($export->status !== OssExportStatus::Ready) {
            $export->update([
                'status' => OssExportStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'completed_at' => Carbon::now('UTC'),
            ]);
        }
    }
}
