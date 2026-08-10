<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The `mollie_subscription_id` a billable carried no longer exists at Mollie, so the
 * gate dropped it.
 *
 * Mollie answers 404 for an id that never existed and 410 Gone for one that did and was
 * removed — the latter is what a lookup returns once the customer has been deleted at
 * Mollie, whether by an operator tidying the dashboard or by a GDPR erasure. Either way
 * the local reference is a fact about the remote world that stopped being true, and
 * clearing it is a state change an app may well need to act on: the subscription is no
 * longer collectable, and the customer has to be moved onto a fresh one.
 *
 * `$reason` is `gone` (410) or `not_found` (404).
 */
class MollieSubscriptionReferenceCleared
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $mollieSubscriptionId,
        public readonly string $reason,
    ) {
    }
}
