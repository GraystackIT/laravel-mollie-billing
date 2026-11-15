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
 * Nothing is charged, no status changes and the invoice is left exactly as booked: taking
 * money from inside a webhook is a decision for a person. The event is written to the
 * audit trail and the admins are told the amount; listen for it to automate a follow-up.
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
    ) {
    }
}
