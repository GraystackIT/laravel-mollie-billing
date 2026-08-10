<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * `$reason` is free text, and only an operator-initiated cancellation carries it —
     * a customer cancelling from the portal states no reason and a scheduled
     * end-of-period cancellation has none to state. It exists because a forced cancel
     * from the admin panel is irreversible and money-affecting, and the audit trail
     * recorded that it happened but not why. Trailing and optional, so existing
     * dispatchers and listeners are unaffected.
     */
    public function __construct(
        public readonly Billable $billable,
        public readonly bool $immediately,
        public readonly ?string $reason = null,
    ) {
    }
}
