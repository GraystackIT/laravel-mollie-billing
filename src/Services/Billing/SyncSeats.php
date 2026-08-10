<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException;

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
        // Clamped at both ends. The portal's `min` attribute is client-side only and this
        // service had no ceiling at all, so a mistyped order of magnitude went straight into
        // SubscriptionAmount::net() and raised a prorata charge in the millions — Mollie
        // rejected the amount, but not before the local seat count had been written.
        $maximum = max(1, (int) config('mollie-billing.max_seats', 1000));

        if ($seats > $maximum) {
            throw new SeatCountExceedsMaximumException($billable, $seats, $maximum);
        }

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
