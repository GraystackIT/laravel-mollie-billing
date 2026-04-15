<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

class DowngradeRequiresMandateException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly string $newPlanCode,
    ) {
        parent::__construct();
    }
}
