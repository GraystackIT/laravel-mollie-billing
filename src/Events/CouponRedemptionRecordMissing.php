<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\Coupon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A coupon was honoured on a payment, but its redemption row could not be written
 * afterwards (the campaign filled up while the payment travelled, or the write failed).
 *
 * The customer has the discount, and a recurring coupon has its marker — nothing needs
 * doing. This is the record that the redemption list is one row short, and why.
 */
class CouponRedemptionRecordMissing
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly Coupon $coupon,
        public readonly int $discountNet,
        public readonly string $error,
    ) {
    }
}
