<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refund that had been booked did not reach the customer after all.
 *
 * Mollie reported it as `failed` or `canceled` after the credit note was written — the
 * bank bounced it, or it was cancelled while still queued. The money is back with the
 * merchant, so the credit note has been taken out of the books again by a reversal
 * document. `$reversal` is that document; `$mollieRefundId` and `$status` say which
 * refund it was and what Mollie reported.
 */
class RefundReversed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $reversal,
        public readonly string $mollieRefundId,
        public readonly string $status,
    ) {
    }
}
