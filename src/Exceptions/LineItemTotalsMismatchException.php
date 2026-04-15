<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use RuntimeException;

class LineItemTotalsMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedNet,
        public readonly int $actualNet,
    ) {
        parent::__construct();
    }
}
