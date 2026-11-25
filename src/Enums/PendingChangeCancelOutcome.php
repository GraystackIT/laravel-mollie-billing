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
     * Refused: the payment has already been paid or authorized, so the change stays and is
     * applied by its webhook. Clearing it would leave the customer charged for nothing.
     */
    case AlreadyPaid = 'already_paid';

    /** Whether the waiting change is gone. */
    public function withdrawn(): bool
    {
        return $this !== self::AlreadyPaid;
    }
}
