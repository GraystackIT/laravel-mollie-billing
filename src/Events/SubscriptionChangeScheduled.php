<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionChangeScheduled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly array $scheduledChange,
        public readonly Carbon $scheduledAt,
    ) {
    }
}
