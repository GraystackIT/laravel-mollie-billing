<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

class InvalidCouponException extends RuntimeException
{
    public function __construct(
        public readonly ?Billable $billable,
        public readonly string $couponCode,
        private readonly string $reason,
    ) {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
