<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A period was collected at less than the configuration that applies in it costs.
 *
 * Mollie creates a recurring payment with the amount the subscription holds at that
 * moment, and a change can come too late for it. Two cases are recognised with certainty:
 *
 *  - `renewal_created_before_change` — a seat or addon was booked after Mollie had already
 *    created the next debit; that renewal settles at the old amount while the new
 *    configuration is active for the whole period.
 *  - `plan_change_spanned_renewal` — a plan change's prorata payment (SEPA) was still
 *    pending when the period renewed. The renewal was collected for the old plan; the
 *    change then took effect in the middle of the period that renewal had paid for.
 *
 * The difference is collected automatically, as a separate recurring payment with its own
 * invoice, exactly once per case (`Services\Billing\UndercollectionCharge`); the customer
 * is told the amount and the reason. `$chargePaymentId` is that payment. It is null when
 * the balance could not be charged — the subscription is past due or has ended, there is
 * no mandate, or Mollie refused the payment — and only then are the admins told.
 */
class RenewalUndercollected
{
    use Dispatchable;
    use SerializesModels;

    public const REASON_RENEWAL_CREATED_BEFORE_CHANGE = 'renewal_created_before_change';

    public const REASON_PLAN_CHANGE_SPANNED_RENEWAL = 'plan_change_spanned_renewal';

    /**
     * @param  ?BillingInvoice  $invoice  the renewal that paid for the period
     * @param  int  $expectedNet  what the period costs on the configuration that applies in it
     * @param  int  $collectedNet  what was collected for it
     * @param  int  $shortfallNet  the difference — always positive
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly ?BillingInvoice $invoice,
        public readonly int $expectedNet,
        public readonly int $collectedNet,
        public readonly int $shortfallNet,
        public readonly string $reason,
        /** The payment created to collect the difference; null when it could not be charged automatically. */
        public readonly ?string $chargePaymentId = null,
    ) {
    }
}
