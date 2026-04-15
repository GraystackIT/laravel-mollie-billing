<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use RuntimeException;

class NonEuCountryException extends RuntimeException
{
    public function __construct(
        public readonly string $country,
    ) {
        parent::__construct();
    }
}
