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

    /**
     * Remove one addon from whatever is active *at the time of the write*.
     *
     * Same reasoning as EnableAddon: `update()` takes an absolute list, so read and write
     * must share one critical section — the change lock, entered with a closure payload,
     * never a `DB::transaction` wrapped around the Mollie pipeline. See
     * {@see EnableAddon::handle()} for the full scenario.
     */
    public function handle(Billable $billable, string $addonCode): void
    {
        $this->updateSubscription->update($billable, fn (Billable $locked): array => [
            'addons' => array_values(array_diff($locked->getActiveBillingAddonCodes(), [$addonCode])),
        ]);
    }
}
