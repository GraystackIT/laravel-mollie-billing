<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class InvalidRefundTargetException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;
}
