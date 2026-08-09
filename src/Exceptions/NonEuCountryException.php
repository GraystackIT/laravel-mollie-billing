<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use RuntimeException;

class NonEuCountryException extends RuntimeException
{
    public function __construct(
        public readonly string $country,
    ) {
        // Spelled out: this surfaces verbatim in the admin panel's mismatch-resolution
        // modal, which reports `$e->getMessage()`, and an empty string there read as a
        // silent failure.
        parent::__construct(
            "VAT cannot be calculated for country [{$country}] — it is neither in the EU "
            .'nor configured under mollie-billing.additional_countries.'
        );
    }
}
