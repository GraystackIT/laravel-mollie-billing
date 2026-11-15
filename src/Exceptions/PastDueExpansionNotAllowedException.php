<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Seats or a paid addon were to be added while the subscription is past due.
 *
 * The last recurring charge failed, so the running period has not been paid. A seat or
 * addon added there prorates against a lapsed period — a factor of 0 — and was simply
 * free until the charge was recovered, on a subscription that already owes money. The
 * customer settles the open payment first; reducing seats, switching an addon off, a free
 * addon and a plan or interval change (which starts a fresh, fully charged period) stay
 * available.
 *
 * The message is the customer-facing text, translated.
 */
class PastDueExpansionNotAllowedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $addonCodes  paid addons the change tried to add
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly int $extraSeats = 0,
        public readonly array $addonCodes = [],
    ) {
        parent::__construct((string) __('billing::portal.error_past_due_expansion'));
    }
}
