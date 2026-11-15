<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The portal's one call for "a tenant who was given their plan buys something paid".
 *
 * The addons and the seats screen both used to answer such a tenant with a notice and a
 * link to the plan page — where the only way forward was to give up the gift and pay for
 * the whole plan. They now start the purchase directly: this collects what the two
 * screens share (who is eligible, one payment per intent, remembering the payment) around
 * `UpgradeLocalToMollie::handleKeepingGrant()`, which owns the pricing.
 */
class StartGrantExtrasPurchase
{
    public function __construct(
        private readonly UpgradeLocalToMollie $upgrade,
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * Whether this billable is on a running gift that paid extras can be bought on top of.
     */
    public function eligible(?Billable $billable): bool
    {
        if ($billable === null || ! $billable->isLocalBillingSubscription()) {
            return false;
        }

        $endsAt = $billable->getBillingSubscriptionEndsAt();

        return $endsAt !== null
            && $endsAt->isFuture()
            && $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Active
            && $billable->getBillingSubscriptionPlanCode() !== null;
    }

    /**
     * Whether this exact change would cost anything — i.e. needs the purchase at all.
     * A free addon or a seat count inside the plan's included seats does not.
     *
     * @param  array<int, string>  $addonCodes  the complete addon set after the change
     */
    public function needsPayment(Billable $billable, array $addonCodes, int $seats): bool
    {
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly');

        if ($planCode === '') {
            return false;
        }

        if ($seats > $this->catalog->includedSeats($planCode)) {
            return true;
        }

        foreach (array_diff($addonCodes, $billable->getActiveBillingAddonCodes()) as $code) {
            if (! $this->catalog->addonIsFree((string) $code, $interval)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start the Mollie payment and return where to send the customer.
     *
     * One payment per intent: a double-click, or Back from Mollie and click again,
     * would otherwise mint a second first payment — both settle, and the second is
     * reported as a duplicate after the money has moved.
     *
     * @param  array<int, string>  $addonCodes  the complete addon set after the purchase
     *
     * @throws SubscriptionChangeInProgressException when a purchase is already being started
     */
    public function handle(Billable $billable, array $addonCodes, int $seats): ?string
    {
        $key = $billable instanceof Model ? (string) $billable->getKey() : spl_object_hash($billable);
        $lock = Cache::lock('billing:checkout-submit:'.$key, 60);

        if (! $lock->get()) {
            throw new SubscriptionChangeInProgressException($billable);
        }

        try {
            $result = $this->upgrade->handleKeepingGrant($billable, $addonCodes, $seats);

            if (! empty($result['payment_id'])) {
                $billable->recordPendingFirstPayment((string) $result['payment_id']);
            }

            return $result['checkout_url'] ?? null;
        } finally {
            $lock->release();
        }
    }
}
