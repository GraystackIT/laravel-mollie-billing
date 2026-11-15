<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chargeback that had been booked was reversed: the dispute was won and the money is
 * back. `$reversal` is the document that takes the chargeback's credit note out of the
 * books again; `$original` is the invoice whose payment stands after all.
 */
class ChargebackReversed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $original,
        public readonly BillingInvoice $reversal,
        public readonly string $mollieChargebackId,
    ) {
    }
}
