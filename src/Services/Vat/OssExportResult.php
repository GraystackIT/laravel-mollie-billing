<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

final class OssExportResult
{
    /**
     * @param  list<string>  $currencies  ISO-4217 codes present in the export, sorted
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $bytes,
        public readonly int $rows,
        public readonly array $currencies = [],
    ) {
    }
}
