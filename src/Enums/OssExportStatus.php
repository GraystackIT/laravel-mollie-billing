<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

enum OssExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return __('billing::enums.oss_export_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'zinc',
            self::Processing => 'blue',
            self::Ready => 'green',
            self::Failed => 'red',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }
}
