<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The first payment for a subscription was received from Mollie, but creating
 * the corresponding Mollie subscription afterwards failed. The invoice has
 * been persisted (the customer paid) but the billable has no active Mollie
 * subscription — manual intervention is required.
 */
class SubscriptionActivationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $planCode,
        public readonly string $interval,
        public readonly string $paymentId,
        public readonly int $invoiceId,
        public readonly string $reason,
    ) {}
}
