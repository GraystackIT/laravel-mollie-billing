<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

/**
 * A new first payment was to be started while an earlier one is paid and its activation
 * is still running or waiting for its retry.
 *
 * That billable has no accessible subscription yet, so the checkout's own guard let the
 * order through — and the customer paid a second time for the subscription the first
 * payment is about to create. The way forward is the return page, which follows the
 * activation to its end (and to the refund, should it never finish).
 *
 * The message is the customer-facing text in English, for the log; a screen shows the same
 * sentence in the reader's language through its translation key.
 */
class ActivationInFlightException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    public function __construct(public readonly Billable $billable)
    {
        parent::__construct((string) __('billing::checkout.activation_in_flight', [], 'en'));
        $this->withTranslation('billing::checkout.activation_in_flight');
    }
}
