<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GrantRevoked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly Coupon $coupon,
        public readonly CouponRedemption $redemption,
        public readonly ?string $reason = null,
    ) {
    }
}
