<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CountryMismatchResolved
{
    use Dispatchable;
    use SerializesModels;

    /**
     * `$justification` is the operator's free-text reason. It carries on the event
     * rather than being read back off the billable because the audit listener needs
     * it: resolving a mismatch is a tax decision made by hand, and the log recorded
     * that it happened but never why.
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly BillingCountryMismatch $mismatch,
        public readonly mixed $resolvedBy,
        public readonly ?string $justification = null,
    ) {
    }
}
