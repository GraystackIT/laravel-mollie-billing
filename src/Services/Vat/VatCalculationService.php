<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Vat;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\CountryResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mpociot\VatCalculator\Exceptions\VATCheckUnavailableException;
use Mpociot\VatCalculator\VatCalculator;
use Throwable;

class VatCalculationService
{
    /**
     * SOAP fault codes VIES returns for input it considers unusable. Everything
     * else it faults on (MS_UNAVAILABLE, SERVER_BUSY, TIMEOUT,
     * GLOBAL_MAX_CONCURRENT_REQ, …) is a service problem, not a verdict.
     *
     * @var list<string>
     */
    private const VIES_INVALID_INPUT_FAULTS = [
        'INVALID_INPUT',
        'INVALID_REQUESTER_INFO',
    ];

    /** Guard so an unconfigured seller country is logged once per process, not per line item. */
    private static bool $sellerCountryWarned = false;

    public function __construct(
        private readonly VatCalculator $vatCalculator,
    ) {
        // `vat_calculator.forward_soap_faults` is set in the service provider's
        // register(), not here: mpociot snapshots its config in its own constructor, and
        // the injected instance is built before this body runs — so flipping the flag
        // here never reached it. `fetchViesDetails()` still treats a bare `false` as an
        // outage, which covers calculators an app resolved even earlier.
    }

    /**
     * Compute VAT split for a given net amount and country.
     *
     * Reverse-charge (0% VAT) applies only to *cross-border* EU B2B supplies
     * (Art. 196 VAT Directive): the billable needs a persisted VIES validation
     * marked `valid=true` **and** its country must differ from the merchant's own
     * country. A domestic B2B supply carries domestic VAT even with a valid VAT
     * number. The validation must be persisted on the billable beforehand via
     * `validateAndPersist()` — `calculate()` itself never talks to VIES.
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

        // B2B reverse-charge: an active VIES validation marked valid=true for an
        // EU country other than the seller's -> no VAT. Reads only persisted
        // state, never VIES.
        if ($isEu && $billable !== null && $this->reverseChargeApplies($billable, $country)) {
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
     * The merchant's own country as an ISO alpha-2 code, or null when it is not
     * configured. Reverse-charge cannot be decided without it.
     *
     * Sources: `mollie-billing.vat.seller_country` (explicit), falling back to
     * the address already used for invoice PDFs
     * (`mollie-billing.invoices.seller.address.country`).
     */
    public function sellerCountry(): ?string
    {
        foreach ([
            config('mollie-billing.vat.seller_country'),
            config('mollie-billing.invoices.seller.address.country'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return CountryResolver::isoFromVatPrefix($candidate);
            }
        }

        return null;
    }

    /**
     * Single decision point for "is this supply reverse-charged?".
     *
     * Used by `calculate()` and intended for every price-display site so the
     * label and the amount can never disagree. Three conditions must hold:
     *
     *   1. the billable has a persisted VIES validation marked `valid=true`;
     *   2. the VAT number's country differs from the merchant's own country;
     *   3. the country being invoiced differs from the merchant's own country.
     *
     * (2) and (3) are the same test in practice — `ValidatesVatNumber` forces the
     * VAT prefix to equal the billing country — but both are checked because
     * either one alone would let a domestic supply through if the two ever
     * diverge (imported data, an admin edit).
     *
     * When the seller country is not configured we cannot tell domestic from
     * cross-border. `mollie-billing.vat.require_seller_country` decides that case:
     * `true` refuses reverse-charge (safe: VAT is charged and can be corrected),
     * `false` (default, backwards compatible) grants it and logs a warning.
     */
    public function reverseChargeApplies(Billable $billable, ?string $invoiceCountry = null): bool
    {
        $validation = $this->currentValidation($billable);
        if ($validation === null) {
            return false;
        }

        $sellerCountry = $this->sellerCountry();

        if ($sellerCountry === null) {
            $this->warnAboutMissingSellerCountry();

            return config('mollie-billing.vat.require_seller_country', false) !== true;
        }

        $customerCountry = $this->validationCountry($validation);
        if ($customerCountry !== null && CountryResolver::sameCountry($customerCountry, $sellerCountry)) {
            return false;
        }

        $invoiceCountry ??= $billable->getBillingCountry();
        if ($invoiceCountry !== null && CountryResolver::sameCountry($invoiceCountry, $sellerCountry)) {
            return false;
        }

        return true;
    }

    /**
     * Live VIES validation without persistence. For Livewire form feedback.
     *
     * A VIES outage is never reported as "invalid": it raises
     * ViesUnavailableException so the caller can surface "try again later"
     * instead of telling a legitimate business its VAT number is wrong.
     *
     * @throws ViesUnavailableException when the VIES service is unreachable
     */
    public function validateVatNumber(string $vatNumber): bool
    {
        $details = $this->fetchViesDetails($vatNumber);

        return $this->interpretViesDetails($details)['valid'];
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
     * error instead of letting the user proceed with an unverified state, and so
     * a transient outage never persists a `valid=false` row that would suppress
     * reverse-charge for good.
     *
     * `country_code` is stored as the ISO alpha-2 code, not the VAT prefix, so it
     * lines up with `billing_country` and every other country column (`EL…` is
     * persisted as `GR`).
     *
     * @throws ViesUnavailableException if VIES is unreachable — caller must abort the save flow
     */
    public function validateAndPersist(Billable $billable, string $vatNumber): BillingVatValidation
    {
        if (! ($billable instanceof Model)) {
            throw new \InvalidArgumentException('Billable must be an Eloquent model to persist VAT validations.');
        }

        $normalized = CountryResolver::normalizeVatNumber($vatNumber);

        // getVATDetails throws on transport failures; re-wrap as ViesUnavailableException.
        $interpreted = $this->interpretViesDetails($this->fetchViesDetails($normalized));

        $validation = new BillingVatValidation;
        $validation->billable_type = $billable->getMorphClass();
        $validation->billable_id = $billable->getKey();
        $validation->vat_number = $normalized;
        $validation->country_code = CountryResolver::isoFromVatPrefix(substr($normalized, 0, 2));
        $validation->valid = $interpreted['valid'];
        $validation->vies_response = $interpreted['payload'];
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
        $vatNumber = CountryResolver::normalizeVatNumber($vatNumber);

        try {
            $details = $this->vatCalculator->getVATDetails($vatNumber);
        } catch (VATCheckUnavailableException $e) {
            if ($this->faultMeansInvalidInput($e->getMessage())) {
                // VIES rejected the request itself (malformed number / requester
                // info). That is a verdict, not an outage.
                return false;
            }

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

        // An invalid-but-well-formed number comes back as a response object with
        // `valid => false`. A bare `false` from the SOAP branch means mpociot
        // caught a SoapFault and swallowed it (see `forward_soap_faults`), i.e.
        // VIES never answered — which must not be shown as "invalid".
        // The GB/HMRC branch is REST and does return `false` for a real 400/404.
        if ($details === false && ! $this->isHmrcNumber($vatNumber)) {
            throw new ViesUnavailableException("VIES temporarily unavailable: no response for {$vatNumber}.");
        }

        return $details;
    }

    /**
     * Normalise a VIES/HMRC response into a validity verdict plus the payload to
     * persist as the audit trail.
     *
     * @param  object|array<string,mixed>|false  $details
     * @return array{valid:bool,payload:array<string,mixed>}
     */
    private function interpretViesDetails($details): array
    {
        // SOAP returns an object; the UK HMRC branch returns an array with
        // 'vatNumber' as the validity marker. Both are coerced to a plain
        // associative array for storage.
        if (is_object($details)) {
            $payload = json_decode(json_encode($details), true) ?: [];

            return ['valid' => (bool) ($payload['valid'] ?? false), 'payload' => $payload];
        }

        if (is_array($details)) {
            return ['valid' => isset($details['vatNumber']), 'payload' => $details];
        }

        // false → invalid (mpociot returns false on validation failure)
        return ['valid' => false, 'payload' => []];
    }

    private function faultMeansInvalidInput(string $message): bool
    {
        foreach (self::VIES_INVALID_INPUT_FAULTS as $fault) {
            if (str_contains(strtoupper($message), $fault)) {
                return true;
            }
        }

        return false;
    }

    private function isHmrcNumber(string $vatNumber): bool
    {
        return strtoupper(substr($vatNumber, 0, 2)) === 'GB';
    }

    private function currentValidation(Billable $billable): ?BillingVatValidation
    {
        if (! method_exists($billable, 'currentVatValidation')) {
            return null;
        }

        $validation = $billable->currentVatValidation();

        return $validation instanceof BillingVatValidation && $validation->valid === true
            ? $validation
            : null;
    }

    /**
     * The customer's country for a validation: the stored ISO code, falling back
     * to the VAT number's prefix for rows written before EL/GR was normalised.
     */
    private function validationCountry(BillingVatValidation $validation): ?string
    {
        $stored = (string) ($validation->country_code ?? '');
        if ($stored !== '') {
            return CountryResolver::isoFromVatPrefix($stored);
        }

        $number = (string) ($validation->vat_number ?? '');

        return strlen($number) >= 2
            ? CountryResolver::isoFromVatPrefix(substr($number, 0, 2))
            : null;
    }

    private function warnAboutMissingSellerCountry(): void
    {
        if (self::$sellerCountryWarned) {
            return;
        }

        self::$sellerCountryWarned = true;

        Log::warning(
            'mollie-billing: seller country is not configured, so domestic B2B supplies cannot be '
            .'distinguished from cross-border ones and reverse-charge is granted on VIES validity alone. '
            .'Set BILLING_SELLER_COUNTRY (or mollie-billing.vat.seller_country).'
        );
    }

    private function hasAdditionalCountry(string $country): bool
    {
        return config('mollie-billing.additional_countries.'.$country) !== null;
    }
}
