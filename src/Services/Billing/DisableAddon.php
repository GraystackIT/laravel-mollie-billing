<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;

class DisableAddon
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    public function handle(Billable $billable, string $addonCode): void
    {
        $current = $billable->getActiveBillingAddonCodes();
        $next = array_values(array_diff($current, [$addonCode]));

        $this->updateSubscription->update($billable, [
            'addons' => $next,
        ]);
    }
}
