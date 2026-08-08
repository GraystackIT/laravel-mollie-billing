<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mollie collected a recurring charge for a subscription that no longer runs.
 *
 * Reachable whenever a local cancellation did not land at Mollie: `CancelSubscription`
 * writes the Cancelled status even when `cancelAtMollie()` fails, and
 * `RetrySubscriptionCancelJob` gives up after 24 hours. From then on Mollie keeps
 * charging a customer whose access has ended.
 *
 * The invoice is written — the money arrived and the customer is entitled to a document,
 * and an operator needs something to refund against — but the period anchor is not moved,
 * no wallet is recharged and no status changes. Listen for this to automate the refund and
 * a second cancellation attempt.
 */
class RenewalCollectedForEndedSubscription
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $paymentId,
        public readonly int $invoiceId,
        public readonly int $amountGross,
        public readonly string $status,
    ) {
    }
}
