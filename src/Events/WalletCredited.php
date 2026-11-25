<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once the credit has committed. A credit made inside a caller's transaction
 * (the prorata webhook's wallet step, a plan change's phase 3) used to reach its listeners
 * at once — and again on the retry after that transaction rolled back, so an audit trail
 * recorded a credit that never persisted, and then the real one.
 */
class WalletCredited implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $usageType,
        public readonly int $units,
        public readonly string $reasonText,
    ) {
    }
}
