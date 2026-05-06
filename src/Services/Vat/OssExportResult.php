<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

final class OssExportResult
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $bytes,
        public readonly int $rows,
    ) {
    }
}
