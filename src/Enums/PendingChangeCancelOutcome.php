<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Enums;

/**
 * What became of a customer's request to cancel a plan change that waits for its payment
 * ({@see \GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::tryCancelPendingPlanChange()}).
 */
enum PendingChangeCancelOutcome: string
{
    /** The payment was cancelled at Mollie and the waiting change cleared. */
    case Cancelled = 'cancelled';

    /**
     * The waiting change was cleared without a Mollie cancel: there was no payment, it was
     * already dead at Mollie, or it could not be cancelled and was recorded as orphaned
     * (it is refunded if it settles after all).
     */
    case Cleared = 'cleared';

    /**
     * Refused: the payment has already been paid or authorized, or its run has booked it, so
     * the change stays. Clearing it would leave the customer charged for nothing.
     */
    case AlreadyPaid = 'already_paid';

    /**
     * Nothing was waiting any more when the cancel arrived — the webhook had carried the
     * change out, or it had failed. Nothing was cancelled.
     */
    case NothingPending = 'nothing_pending';

    /** Whether this cancel withdrew a waiting change. */
    public function withdrawn(): bool
    {
        return $this === self::Cancelled || $this === self::Cleared;
    }
}
