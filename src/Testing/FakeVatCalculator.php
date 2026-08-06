<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Testing;

use Mpociot\VatCalculator\VatCalculator;

/**
 * VatCalculator whose VIES lookup answers from an in-memory allow-list instead of
 * talking to the real VIES SOAP service. Bound by MollieBillingFake so
 * `MollieBilling::fake()->vatNumberValidates('ATU12345678')` actually reaches
 * VatCalculationService::validateVatNumber() / validateAndPersist().
 *
 * Everything else (rates, reverse-charge rules) keeps the real implementation —
 * those are pure lookups with no network involved.
 */
class FakeVatCalculator extends VatCalculator
{
    /**
     * @param  array<int, string>  $validNumbers  shared by reference with the fake,
     *                                            so numbers registered after this
     *                                            calculator was built still count
     */
    public function __construct(private array &$validNumbers, mixed $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Always answers with a details object, never `false`.
     *
     * That mirrors what VIES itself does — it reports an unknown number as
     * `valid: false`. A bare `false` from mpociot means a swallowed SoapFault, and
     * VatCalculationService rightly turns that into ViesUnavailableException, so
     * returning it here would make every unlisted number look like an outage.
     *
     * @param  string  $vatNumber
     * @return object
     */
    public function getVATDetails($vatNumber)
    {
        $normalized = strtoupper(preg_replace('/[\s\-.,]/', '', (string) $vatNumber) ?? '');
        $valid = in_array($normalized, $this->validNumbers, true);

        return (object) [
            'countryCode' => substr($normalized, 0, 2),
            'vatNumber' => substr($normalized, 2),
            'valid' => $valid,
            'name' => $valid ? 'Fake VIES Company' : '---',
            'address' => $valid ? 'Fake VIES Address' : '---',
            'requestDate' => date('Y-m-d'),
        ];
    }
}
