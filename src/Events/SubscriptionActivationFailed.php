<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Subscription activation failed after Mollie processed the initial payment or
 * mandate. Two flavours:
 *   - First-payment flow: the invoice has been persisted (the customer paid)
 *     but the Mollie subscription couldn't be created — manual intervention.
 *     `$invoiceId` references that invoice.
 *   - Trial flow: the mandate was captured but the Mollie subscription couldn't
 *     be created. No invoice exists (no money flowed), so `$invoiceId` is null.
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
        public readonly ?int $invoiceId,
        public readonly string $reason,
    ) {}
}
