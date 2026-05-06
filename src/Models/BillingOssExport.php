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

    public function isReady(): bool
    {
        return $this->status === OssExportStatus::Ready
            && $this->disk !== null
            && $this->path !== null;
    }
}
