<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditNoteIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $creditNote,
        public readonly ?BillingInvoice $originalInvoice,
    ) {
    }
}
