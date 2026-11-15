<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\Coupon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExtended
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly ?CarbonInterface $previousEndsAt,
        public readonly CarbonInterface $newEndsAt,
        // Null when free time is given to a Local subscription that no coupon stands
        // behind (an app activating a plan with an end date of its own).
        public readonly ?Coupon $coupon = null,
    ) {
    }
}
