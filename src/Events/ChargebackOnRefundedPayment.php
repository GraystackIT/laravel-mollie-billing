<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's bank pulled back a payment that had already been refunded, in full or in
 * part: the merchant has paid that money back twice (plus Mollie's chargeback fee).
 *
 * No credit note is written for the part that was refunded already — the invoice has been
 * credited for it — so nothing in the books shows the second payout. `$uncreditedAmount`
 * is that part, in minor units of the invoice's currency; `$chargebackAmount` is what the
 * bank pulled back in total. The way to recover it is to dispute the chargeback with the
 * proof of the refund. Dispatched once per chargeback.
 */
class ChargebackOnRefundedPayment
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $original,
        public readonly string $mollieChargebackId,
        public readonly int $chargebackAmount,
        public readonly int $uncreditedAmount,
    ) {
    }
}
