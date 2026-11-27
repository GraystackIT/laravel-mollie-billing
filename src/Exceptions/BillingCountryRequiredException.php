<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

/**
 * A purchase was started for a billable that has no billing country on file.
 *
 * Without one there is no VAT rate to price with. The checkout collects the address
 * before anything is priced, so this is only reachable where a subscription exists
 * without a checkout — above all a plan that was *given* to a tenant by an operator.
 *
 * Extends {@see NonEuCountryException} because that is what the pricing probe used to
 * throw for the empty country, and callers that catch it must keep working — but it is a
 * different situation with a different remedy (enter the billing details), and the portal
 * tells the two apart.
 */
class BillingCountryRequiredException extends NonEuCountryException
{
    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.billing_country_required';
    }

    /** @return array<string, string|int|float> */
    protected function defaultTranslationParameters(): array
    {
        return [];
    }

    public function __construct()
    {
        parent::__construct('');

        $this->message = 'No billing country is on file — the billing details have to be completed before anything can be purchased.';
    }
}
