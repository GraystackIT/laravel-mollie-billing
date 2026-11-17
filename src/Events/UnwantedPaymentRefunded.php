<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment arrived for something that is no longer wanted and was returned
 * automatically — see `Services\Webhook\UnwantedPaymentRefund`.
 *
 * `$creditNote` is null when the refund could not be posted yet and is queued for
 * `RetryRefundLineJob`; that is also the one case in which the admins are told.
 */
class UnwantedPaymentRefunded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $invoice,
        public readonly ?BillingInvoice $creditNote,
        public readonly string $paymentId,
        public readonly string $reason,
    ) {
    }
}
