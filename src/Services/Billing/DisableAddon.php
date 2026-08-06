<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * must share one lock or a concurrent enable/disable pair silently drops the other's
     * addon. See EnableAddon::handle() for the full scenario.
     */
    public function handle(Billable $billable, string $addonCode): void
    {
        DB::transaction(function () use ($billable, $addonCode): void {
            if ($billable instanceof Model) {
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            $next = array_values(array_diff($billable->getActiveBillingAddonCodes(), [$addonCode]));

            $this->updateSubscription->update($billable, [
                'addons' => $next,
            ]);
        });
    }
}
