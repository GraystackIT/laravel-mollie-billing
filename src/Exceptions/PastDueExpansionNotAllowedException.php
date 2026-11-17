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
    /** "Nothing new can be booked until the open payment is settled." */
    public const BOOKING = 'billing::portal.error_past_due_booking';

    /** A booking other than seats or addons, refused while past due. */
    public static function booking(Billable $billable): self
    {
        return new self($billable, 0, [], self::BOOKING);
    }

    /** A plan or interval change — now or scheduled — while only an overage is open. */
    public const PLAN_CHANGE = 'billing::portal.error_past_due_plan_change';

    /**
     * Says what the customer can do instead: settle the open amount, and that seats and
     * add-ons can still be reduced meanwhile.
     */
    public static function planChange(Billable $billable): self
    {
        return new self($billable, 0, [], self::PLAN_CHANGE);
    }

    /**
     * @param  array<int, string>  $addonCodes  paid addons the change tried to add
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly int $extraSeats = 0,
        public readonly array $addonCodes = [],
        ?string $messageKey = null,
    ) {
        // Seats and addons keep their own sentence; everything else that is a booking —
        // a plan change, a product, a new checkout, extras on a grant — says the
        // general one (`self::BOOKING`).
        parent::__construct((string) __($messageKey ?? 'billing::portal.error_past_due_expansion'));
    }
}
