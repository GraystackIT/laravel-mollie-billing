<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

class UsageLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly string $usageType,
        public readonly int $balance,
        public readonly int $attemptedQuantity,
    ) {
        parent::__construct();
    }
}
