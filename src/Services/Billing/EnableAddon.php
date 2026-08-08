<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EnableAddon
{
    public function __construct(
        private readonly UpdateSubscription $updateSubscription,
    ) {
    }

    /**
     * Add one addon to whatever is active *at the time of the write*.
     *
     * `UpdateSubscription::update()` takes an absolute addon list, so the read of the
     * current codes and the write of the new list have to sit inside one lock. Computing
     * the list from the caller's model first — as this service used to — cannot be
     * repaired by the row lock inside `update()`: two admins enabling A and B near
     * simultaneously would both read `[]`, one writes `[A]` and the other overwrites it
     * with `[B]`, silently disabling an addon that was just paid for, complete with an
     * `AddonDisabled` event and a Mollie amount patched without it.
     *
     * Taking the lock here, before the read, is what makes the mutation relative: the
     * second caller blocks until the first has committed and then sees `[A]`. The
     * transaction in `update()` nests as a savepoint and its lock is a no-op
     * re-acquisition.
     *
     * @param  array<int, string>|null  $couponCodes
     */
    public function handle(Billable $billable, string $addonCode, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        DB::transaction(function () use ($billable, $addonCode, $couponCode, $couponCodes): void {
            $current = $this->lockAndReadAddons($billable);
            $next = array_values(array_unique([...$current, $addonCode]));

            $payload = ['addons' => $next];

            if ($couponCodes !== null && $couponCodes !== []) {
                $payload['coupon_codes'] = $couponCodes;
            } elseif ($couponCode !== null && $couponCode !== '') {
                $payload['coupon_code'] = $couponCode;
            }

            $this->updateSubscription->update($billable, $payload);
        });
    }

    /**
     * @return array<int, string>
     */
    private function lockAndReadAddons(Billable $billable): array
    {
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

        return $billable->getActiveBillingAddonCodes();
    }
}
