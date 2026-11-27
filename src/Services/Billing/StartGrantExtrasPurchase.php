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
        // Optional so that a subclass or a partial mock built with the two arguments
        // this constructor used to take keeps working.
        private ?PendingFirstPayment $pendingFirstPayment = null,
    ) {
        $this->pendingFirstPayment ??= app(PendingFirstPayment::class);
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
     * reported as a duplicate after the money has moved. The lock covers the first; the
     * payment on record (`PendingFirstPayment`) the second.
     *
     * @param  array<int, string>  $addonCodes  the complete addon set after the purchase
     *
     * @throws SubscriptionChangeInProgressException when a purchase is already being started
     * @throws \GraystackIT\MollieBilling\Exceptions\PendingPaymentForAnotherOrderException when an
     *         earlier purchase of other extras is still open and Mollie would not withdraw it
     */
    public function handle(Billable $billable, array $addonCodes, int $seats): ?string
    {
        // Past due — for an unpaid renewal or for an overage debt — books nothing new.
        // `eligible()` already keeps the screens from offering this; the service is also
        // what an app calls directly.
        if ($billable->isBillingPastDue()) {
            throw \GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException::booking($billable);
        }

        $key = $billable instanceof Model ? (string) $billable->getKey() : spl_object_hash($billable);
        $lock = Cache::lock('billing:checkout-submit:'.$key, 60);

        if (! $lock->get()) {
            throw new SubscriptionChangeInProgressException($billable);
        }

        try {
            // The lock is released as soon as the payment exists, so Back from Mollie and
            // buy again is sequential and passes it. The payment on record is this
            // purchase while it is open for the same extras; see PendingFirstPayment.
            $plan = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
            $included = $plan !== '' ? $this->catalog->includedSeats($plan) : 0;
            $reusable = $this->pendingFirstPayment->redirectFor($billable, [
                'plan_code' => $plan,
                'interval' => (string) ($billable->getBillingSubscriptionInterval() ?? 'monthly'),
                'addon_codes' => array_values(array_unique(array_map('strval', $addonCodes))),
                'extra_seats' => max($seats, $included, $billable->getUsedBillingSeats()) - $included,
                'coupon_code' => null,
                'upgrade_from_local' => true,
                'keep_grant' => true,
            ]);

            if ($reusable !== null) {
                return $reusable;
            }

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
