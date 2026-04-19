<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

class SeatDowngradeRequiredException extends RuntimeException
{
    public function __construct(
        public readonly Billable $billable,
        public readonly int $usedSeats,
        public readonly int $includedSeats,
    ) {
        parent::__construct(
            "Plan change requires reducing seats from {$usedSeats} to {$includedSeats} first."
        );
    }
}
