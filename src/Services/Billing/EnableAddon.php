<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

class EnableAddon
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    /**
     * @param  array<int, string>|null  $couponCodes
     */
    public function handle(Billable $billable, string $addonCode, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        $current = $billable->getActiveBillingAddonCodes();
        $next = array_values(array_unique(array_merge($current, [$addonCode])));

        $payload = ['addons' => $next];

        if ($couponCodes !== null && $couponCodes !== []) {
            $payload['coupon_codes'] = $couponCodes;
        } elseif ($couponCode !== null && $couponCode !== '') {
            $payload['coupon_code'] = $couponCode;
        }

        $this->updateSubscription->update($billable, $payload);
    }
}
