<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Models\Coupon;

/**
 * What became of a code the customer typed into the portal: redeemed or not, and the
 * sentence they are shown either way.
 */
final class PortalCouponOutcome
{
    public function __construct(
        public readonly bool $redeemed,
        public readonly string $message,
        /** Stable reason code of a refusal, for callers that branch on it. */
        public readonly ?string $reason = null,
        public readonly ?Coupon $coupon = null,
    ) {
    }

    public static function redeemed(string $message, Coupon $coupon): self
    {
        return new self(true, $message, null, $coupon);
    }

    public static function refused(string $message, string $reason, ?Coupon $coupon = null): self
    {
        return new self(false, $message, $reason, $coupon);
    }
}
