<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Models;

use GraystackIT\MollieBilling\Casts\UtcDatetime;
use GraystackIT\MollieBilling\Enums\OssExportStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $year
 * @property OssExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $bytes
 * @property int|null $rows_count
 * @property int|string|null $requested_by_user_id
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property string|null $failure_reason
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class BillingOssExport extends Model
{
    protected $table = 'billing_oss_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => OssExportStatus::class,
            'bytes' => 'integer',
            'rows_count' => 'integer',
            'completed_at' => UtcDatetime::class,
            'created_at' => UtcDatetime::class,
            'updated_at' => UtcDatetime::class,
        ];
    }

    /**
     * How long a run may claim to be in flight before it is treated as abandoned.
     *
     * A `queued`/`processing` row is only ever cleared by the process that wrote it, and
     * that process can stop existing: a deploy mid-run, the OOM killer, Ctrl-C on
     * `billing:oss-export`. The queue's own `failed()` hook does not fire when the worker
     * is killed outright, and the command writes `processing` before it starts work. The
     * admin screen reads any such row as "a run is in flight" and refuses to start
     * another, so one dead run blocked every future export — in the one part of the
     * package whose output goes to a tax authority on a deadline. Generously past the
     * job's own 600s timeout, so a slow but living run is never mistaken for a dead one.
     */
    public const STALE_AFTER_MINUTES = 60;

    public function isReady(): bool
    {
        return $this->status === OssExportStatus::Ready
            && $this->disk !== null
            && $this->path !== null;
    }

    /**
     * Runs that still claim to be in flight and plausibly still are.
     */
    public function scopeRunning(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereIn('status', [OssExportStatus::Queued, OssExportStatus::Processing])
            ->where('updated_at', '>=', \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Record abandoned runs as the failures they are, and report how many were found.
     *
     * Called from the operator's own action rather than from a render pass: it writes,
     * and the screen polls.
     */
    public static function reclaimAbandonedRuns(): int
    {
        $cutoff = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->subMinutes(self::STALE_AFTER_MINUTES);

        return self::query()
            ->whereIn('status', [OssExportStatus::Queued, OssExportStatus::Processing])
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status' => OssExportStatus::Failed,
                'failure_reason' => 'The run never reported back — the worker or the command most likely died.',
                'completed_at' => \GraystackIT\MollieBilling\Support\BillingTime::nowUtc(),
            ]);
    }
}
