<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class CouponNotStackableException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.coupon_not_stackable';
    }
}
