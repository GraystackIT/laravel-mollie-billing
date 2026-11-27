<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

class NonEuCountryException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.non_eu_country';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return ['country' => $this->country];
    }

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
