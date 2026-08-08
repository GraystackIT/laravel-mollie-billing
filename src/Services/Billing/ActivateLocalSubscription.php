<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Billing;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionMetaHygiene;
use Illuminate\Database\Eloquent\Model;

class ActivateLocalSubscription
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
        private readonly WalletUsageService $walletService,
    ) {
    }

    /**
     * @param  array<int, string>  $addonCodes
     */
    public function handle(
        Billable $billable,
        string $planCode,
        string $interval,
        array $addonCodes = [],
        int $durationDays = 0,
    ): void {
        /** @var Model&Billable $billable */
        // Plan-scoped meta must not survive into a freshly activated plan. Nothing
        // clears `subscription_meta` on cancel or on the Cancelled → Expired
        // transition, so a billable that had Pro with seat_count = 10 and later
        // re-activates a free plan (or receives an access grant, where the source is
        // None) would keep 10 seats on a tier that includes 1 — and a stale
        // `active_recurring_coupon` would be read back by CreateSubscription on a
        // later upgrade. UpdateSubscription always rewrites seat_count on a downgrade
        // for the same reason. Identity keys (Mollie ids, checkout handles) are kept:
        // clearing `mollie_subscription_id` would orphan a subscription nobody can
        // cancel afterwards.
        // One list, in SubscriptionMetaHygiene — this one had grown by hand while the
        // webhook activation paths cleared nothing at all, and it was still missing the
        // dunning keys, so a stale `past_due_since` could auto-cancel a brand-new
        // subscription the first time a renewal failed.
        $meta = SubscriptionMetaHygiene::forFreshSubscription($billable->getBillingSubscriptionMeta());
        $meta['seat_count'] = $this->catalog->includedSeats($planCode);

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Local,
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'active_addon_codes' => $addonCodes,
            'subscription_period_starts_at' => BillingTime::nowUtc(),
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_meta' => $meta,
            'subscription_ends_at' => $durationDays > 0 ? BillingTime::nowUtc()->addDays($durationDays) : null,
            // Cleared together with the meta entry — the period-end dispatcher keys off
            // this column alone and would otherwise keep firing for a dropped change.
            'scheduled_change_at' => null,
        ])->save();

        $includedUsages = $this->catalog->includedUsages($planCode, $interval);

        foreach ($includedUsages as $type => $quantity) {
            if ((int) $quantity > 0) {
                $this->walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_activation');
            }
        }

        SubscriptionCreated::dispatch($billable, $planCode, $interval);
    }
}
