<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

class SyncSeats
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    /**
     * @param  array<int, string>|null  $couponCodes
     */
    public function handle(Billable $billable, int $seats, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        $payload = [
            'seats' => max(0, $seats),
        ];

        if ($couponCodes !== null && $couponCodes !== []) {
            $payload['coupon_codes'] = $couponCodes;
        } elseif ($couponCode !== null && $couponCode !== '') {
            $payload['coupon_code'] = $couponCode;
        }

        $this->updateSubscription->update($billable, $payload);
    }
}
