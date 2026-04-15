<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use Mpociot\VatCalculator\Exceptions\VATCheckUnavailableException;
use Mpociot\VatCalculator\VatCalculator;
use Throwable;

class VatCalculationService
{
    public function __construct(
        private readonly VatCalculator $vatCalculator,
    ) {
    }

    /**
     * @return array{net:int,vat:int,gross:int,rate:float}
     */
    public function calculate(string $country, int $netAmount, ?string $vatNumber = null): array
    {
        $country = strtoupper($country);
        $isEu = $this->vatCalculator->shouldCollectVAT($country);
        $hasOverride = $this->hasAdditionalCountry($country);

        if (! $isEu && ! $hasOverride) {
            throw new NonEuCountryException($country);
        }

        // B2B reverse-charge: valid VAT number for an EU country -> no VAT
        if ($vatNumber !== null && $vatNumber !== '' && $isEu && $this->validateVatNumber($vatNumber)) {
            return [
                'net' => $netAmount,
                'vat' => 0,
                'gross' => $netAmount,
                'rate' => 0.0,
            ];
        }

        $rate = $this->vatRateFor($country);
        $vat = (int) round($netAmount * $rate / 100);
        $gross = $netAmount + $vat;

        return [
            'net' => $netAmount,
            'vat' => $vat,
            'gross' => $gross,
            'rate' => $rate,
        ];
    }

    /**
     * Returns the VAT rate as a percentage (e.g. 19.0 for 19%).
     *
     * Resolution order:
     *   1. config('mollie-billing.vat_rate_overrides.{COUNTRY}')
     *   2. config('mollie-billing.additional_countries.{COUNTRY}.vat_rate')
     *   3. mpociot/vat-calculator (decimal -> *100)
     */
    public function vatRateFor(string $country): float
    {
        $country = strtoupper($country);

        $override = config('mollie-billing.vat_rate_overrides.'.$country);
        if ($override !== null) {
            return (float) $override;
        }

        $additional = config('mollie-billing.additional_countries.'.$country.'.vat_rate');
        if ($additional !== null) {
            return (float) $additional;
        }

        return (float) $this->vatCalculator->getTaxRateForCountry($country) * 100.0;
    }

    /**
     * Validate a VAT number against VIES.
     *
     * @throws ViesUnavailableException when the VIES service is unreachable
     */
    public function validateVatNumber(string $vatNumber): bool
    {
        try {
            return $this->vatCalculator->isValidVATNumber($vatNumber);
        } catch (VATCheckUnavailableException $e) {
            throw new ViesUnavailableException(
                'VIES temporarily unavailable: '.$e->getMessage(),
                0,
                $e,
            );
        } catch (Throwable $e) {
            throw new ViesUnavailableException(
                'VIES temporarily unavailable: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function hasAdditionalCountry(string $country): bool
    {
        return config('mollie-billing.additional_countries.'.$country) !== null;
    }
}
