<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;

/**
 * A resume was asked for while the cancel it would undo has not reached Mollie yet.
 *
 * `CancelSubscription` ends the subscription locally even when Mollie does not acknowledge
 * the cancel, and leaves a `pending_subscription_cancel` marker for the hourly sweep. Until
 * that cancel lands the old subscription still collects — and a resume creates a new one, so
 * resuming then gave the customer two subscriptions charging on the same day. The resume
 * tries the cancel once more itself; this is what it answers when Mollie still refuses.
 *
 * A refusal of the state, not a failure: nothing has been written, and the same click works
 * once Mollie answers again.
 */
class PreviousSubscriptionStillRunningException extends InvalidSubscriptionStateException
{
    protected function defaultTranslationKey(): ?string
    {
        return 'billing::exceptions.previous_subscription_still_running';
    }

    public function __construct(public readonly Billable $billable, public readonly string $mollieError = '')
    {
        parent::__construct('Cannot resubscribe yet: the previous Mollie subscription could not be cancelled yet.');
    }
}
