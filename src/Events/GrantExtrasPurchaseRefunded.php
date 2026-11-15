<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tenant paid for extras on top of a gifted plan, and the gift was gone by the time
 * the payment settled — so the purchase was not carried out and the payment is returned.
 *
 * `$invoice` records the payment that came in; `$creditNote` the refund, or null when
 * Mollie could not be asked to pay it out yet (it is then queued under
 * `subscription_meta.pending_refund_retries`). No subscription was created and nothing
 * about the billable's plan, addons or seats changed.
 */
class GrantExtrasPurchaseRefunded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $invoice,
        public readonly ?BillingInvoice $creditNote,
        public readonly string $paymentId,
    ) {
    }
}
