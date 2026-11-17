<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The billable's Mollie subscription was replaced by a new one, because the date of its
 * next charge had to move and Mollie refuses a new start date once a subscription has
 * been charged (see `MollieSubscriptionReplacer`).
 *
 * The old subscription is cancelled at Mollie; the new one carries the same amount (or
 * the one the change asked for), interval, description, metadata, mandate and webhook,
 * and is first charged on `$startDate` — never today or earlier. From here on
 * `subscription_meta.mollie_subscription_id` names `$subscriptionId`. Payments the old
 * subscription created before it was cancelled still arrive and are booked as before:
 * a webhook finds its billable through the payment's metadata, not through the
 * subscription id.
 *
 * `$reason` names what moved the date: `anchor_pin`, `next_charge_date`, `amount_update`.
 */
class MollieSubscriptionReplaced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $previousSubscriptionId,
        public readonly string $subscriptionId,
        public readonly CarbonInterface $startDate,
        public readonly string $reason,
    ) {
    }
}
