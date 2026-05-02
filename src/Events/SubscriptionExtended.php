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
        public readonly Coupon $coupon,
    ) {
    }
}
