<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Concerns;

use GraystackIT\MollieBilling\Exceptions\ViesUnavailableException;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;

/**
 * Live VAT-number validation for Livewire components: format → country-prefix → VIES.
 *
 * Consuming components must declare:
 *   - public ?string $vat_number
 *   - public string  $billing_country
 *   - public ?bool   $vatNumberValid
 *   - public ?string $vatStatusMessage
 *
 * and call `validateVatNumberLive()` from their `updated*` hooks.
 */
trait ValidatesVatNumber
{
    public function validateVatNumberLive(VatCalculationService $vat, string $field = 'vat_number'): void
    {
        $this->resetErrorBag($field);
        $this->vatNumberValid = null;
        $this->vatStatusMessage = null;

        $value = trim((string) $this->vat_number);
        if ($value === '') {
            return;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        $this->vat_number = $normalized;

        if (! preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $normalized)) {
            $this->addError($field, __('billing::checkout.vat_invalid_format'));
            $this->vatNumberValid = false;

            return;
        }

        if (substr($normalized, 0, 2) !== strtoupper($this->billing_country)) {
            $this->addError($field, __('billing::checkout.vat_country_mismatch'));
            $this->vatNumberValid = false;

            return;
        }

        try {
            $isValid = $vat->validateVatNumber($normalized);
        } catch (ViesUnavailableException) {
            $this->vatNumberValid = null;
            $this->vatStatusMessage = __('billing::checkout.vies_unavailable');

            return;
        }

        if (! $isValid) {
            $this->addError($field, __('billing::checkout.vies_validation_failed'));
            $this->vatNumberValid = false;

            return;
        }

        $this->vatNumberValid = true;
        $this->vatStatusMessage = __('billing::checkout.vat_verified');
    }
}
