<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An unpaid overage was given up for good.
 *
 * Fired once per debt, after its own dunning cycle and
 * `ChargeUsageOverageDirectly::MAX_RENEWAL_RECOLLECTIONS` renewals have all failed to
 * collect it. Nothing is owed locally any more; the amounts say what was written off.
 */
class OverageWrittenOff
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly array $lineItems,
        public readonly int $amountNet,
        public readonly int $amountGross,
        public readonly int $attempts,
    ) {
    }
}
