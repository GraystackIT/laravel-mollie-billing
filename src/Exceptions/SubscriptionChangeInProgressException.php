<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Exceptions;

use GraystackIT\MollieBilling\Contracts\Billable;
use RuntimeException;

/**
 * Thrown when a subscription change is attempted while another one is still running.
 *
 * `UpdateSubscription` serialises changes to the same billable with a cache lock rather
 * than holding a database transaction across its three Mollie calls. The loser is refused
 * outright instead of queueing: it would otherwise sit on a worker for the length of a
 * payment plus a PATCH, and everything it validated — seats, addons, the prorata amounts —
 * is stale by the time it gets its turn anyway.
 */
class SubscriptionChangeInProgressException extends RuntimeException
{
    public function __construct(public readonly Billable $billable)
    {
        parent::__construct('Another subscription change for this billable is already in progress.');
    }
}
