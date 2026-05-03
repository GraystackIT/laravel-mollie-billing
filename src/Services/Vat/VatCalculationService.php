<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
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
     * Compute VAT split for a given net amount and country.
     *
     * Reverse-charge (0% VAT) applies if the billable has a current VIES
     * validation marked `valid=true` for an EU country. The validation must
     * be persisted on the billable beforehand via `validateAndPersist()` —
     * `calculate()` itself never talks to VIES.
     *
     * @return array{net:int,vat:int,gross:int,rate:float}
     */
    public function calculate(string $country, int $netAmount, ?Billable $billable = null): array
    {
        $country = strtoupper($country);
        $isEu = $this->vatCalculator->shouldCollectVAT($country);
        $hasOverride = $this->hasAdditionalCountry($country);

        if (! $isEu && ! $hasOverride) {
            throw new NonEuCountryException($country);
        }

        // B2B reverse-charge: an active VIES validation marked valid=true
        // for an EU country -> no VAT. Reads only persisted state, never VIES.
        if ($isEu && $billable !== null && $this->hasActiveReverseCharge($billable)) {
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
     * Live VIES validation without persistence. For Livewire form feedback.
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

    /**
     * Validate a VAT number against VIES and persist the result as an
     * audit-trail entry on the billable.
     *
     * Each successful call appends a new `BillingVatValidation` row. Past
     * entries are never modified, so a tax authority can always trace which
     * validation any given invoice was based on (via `BillingInvoice::vat_validation_id`).
     *
     * Always returns a saved model with `valid` set to either true or false.
     * No row is created when VIES is unreachable — a `ViesUnavailableException`
     * propagates up so the calling Livewire component can surface a "try later"
     * error instead of letting the user proceed with an unverified state.
     *
     * @throws ViesUnavailableException if VIES is unreachable — caller must abort the save flow
     */
    public function validateAndPersist(Billable $billable, string $vatNumber): BillingVatValidation
    {
        if (! ($billable instanceof Model)) {
            throw new \InvalidArgumentException('Billable must be an Eloquent model to persist VAT validations.');
        }

        // getVATDetails throws on transport failures; re-wrap as ViesUnavailableException.
        $details = $this->fetchViesDetails($vatNumber);

        // Normalise: SOAP returns an object, the UK HMRC branch returns an array
        // with 'vatNumber' as the validity marker. Both are coerced to a plain
        // associative array for storage.
        if (is_object($details)) {
            $payload = json_decode(json_encode($details), true) ?: [];
            $isValid = (bool) ($payload['valid'] ?? false);
        } elseif (is_array($details)) {
            $payload = $details;
            $isValid = isset($details['vatNumber']);
        } else {
            // false → invalid (mpociot returns false on validation failure)
            $payload = [];
            $isValid = false;
        }

        $validation = new BillingVatValidation;
        $validation->billable_type = $billable->getMorphClass();
        $validation->billable_id = $billable->getKey();
        $validation->vat_number = $vatNumber;
        $validation->country_code = strtoupper(substr($vatNumber, 0, 2));
        $validation->valid = $isValid;
        $validation->vies_response = $payload;
        $validation->checked_at = BillingTime::nowUtc();
        $validation->save();

        return $validation;
    }

    /**
     * Fetch raw VIES details. Wraps mpociot exceptions as ViesUnavailableException.
     *
     * @return object|array<string,mixed>|false
     *
     * @throws ViesUnavailableException
     */
    private function fetchViesDetails(string $vatNumber)
    {
        try {
            return $this->vatCalculator->getVATDetails($vatNumber);
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

    private function hasActiveReverseCharge(Billable $billable): bool
    {
        if (! method_exists($billable, 'currentVatValidation')) {
            return false;
        }

        $validation = $billable->currentVatValidation();
        return $validation instanceof BillingVatValidation && $validation->valid === true;
    }

    private function hasAdditionalCountry(string $country): bool
    {
        return config('mollie-billing.additional_countries.'.$country) !== null;
    }
}
