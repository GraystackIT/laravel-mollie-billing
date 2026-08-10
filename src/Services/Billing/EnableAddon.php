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
     * Add one addon to whatever is active *at the time of the write*.
     *
     * `UpdateSubscription::update()` takes an absolute addon list, so the read of the
     * current codes and the write of the new list have to sit inside one critical
     * section. Computing the list from the caller's model first cannot be repaired by
     * the row lock inside `update()`: two admins enabling A and B near simultaneously
     * would both read `[]`, one writes `[A]` and the other overwrites it with `[B]`,
     * silently disabling an addon that was just paid for, complete with an
     * `AddonDisabled` event and a Mollie amount patched without it.
     *
     * That critical section is the change lock `update()` already holds, entered by
     * handing it a closure — NOT a `DB::transaction` around the call, which is what
     * this service used to do. `update()` posts payments, refunds and PATCHes to Mollie
     * between its phases, and wrapping it made all of them run inside a transaction a
     * later throw rolls back: the customer was refunded while `refunded_net` went back
     * to 0 (so the retry refunded again), or charged for an addon whose
     * `pending_prorata_change` marker no longer existed when the payment settled.
     *
     * @param  array<int, string>|null  $couponCodes
     */
    public function handle(Billable $billable, string $addonCode, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        $this->updateSubscription->update($billable, function (Billable $locked) use ($addonCode, $couponCode, $couponCodes): array {
            $next = array_values(array_unique([...$locked->getActiveBillingAddonCodes(), $addonCode]));

            $payload = ['addons' => $next];

            if ($couponCodes !== null && $couponCodes !== []) {
                $payload['coupon_codes'] = $couponCodes;
            } elseif ($couponCode !== null && $couponCode !== '') {
                $payload['coupon_code'] = $couponCode;
            }

            return $payload;
        });
    }
}
