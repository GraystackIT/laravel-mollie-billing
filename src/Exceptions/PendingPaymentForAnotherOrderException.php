<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\Concerns\CarriesTranslation;
use RuntimeException;

/**
 * The customer confirmed an order that differs from the first payment still open for
 * them at Mollie, and that payment could not be withdrawn.
 *
 * Sending them to the open payment would book the order they left, at its price; a
 * second payment beside it could be paid as well. Neither is done: the customer is told
 * to finish the earlier payment or to wait until it has expired.
 *
 * `$expiresAt` (UTC) is until when Mollie keeps that payment payable — its own `expiresAt`,
 * or its creation plus `PendingFirstPayment::FIRST_PAYMENT_EXPIRY_MINUTES` — and
 * `$checkoutUrl` the link that still finishes it; either is null when Mollie did not say.
 * The checkout shows both; the other screens show the message alone.
 *
 * The message is the customer-facing text in English, for the log; a screen shows the same
 * sentence in the reader's language through its translation key.
 */
class PendingPaymentForAnotherOrderException extends RuntimeException implements TranslatableException
{
    use CarriesTranslation;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $paymentId,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $checkoutUrl = null,
    ) {
        parent::__construct((string) __('billing::checkout.pending_payment_other_order', [], 'en'));
        $this->withTranslation('billing::checkout.pending_payment_other_order');
    }
}
