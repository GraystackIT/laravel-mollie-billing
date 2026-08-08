<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Scopes\BillingScope;
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
                // Scope-free: with an app-defined applyBillingScope() the scoped query can
                // match nothing, and then this looks like it worked — first() quietly
                // returns null, no row is locked, refresh() still loads fresh data (it uses
                // newQueryWithoutScopes()) and the read-modify-write below runs
                // unserialised. Every other lock read in the package bypasses the scope for
                // this reason.
                $billable->newQuery()
                    ->withoutGlobalScope(BillingScope::class)
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
