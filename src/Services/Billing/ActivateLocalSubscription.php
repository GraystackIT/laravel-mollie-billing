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
        $meta = $billable->getBillingSubscriptionMeta();
        if (! array_key_exists('seat_count', $meta)) {
            $meta['seat_count'] = $this->catalog->includedSeats($planCode);
        }

        $billable->forceFill([
            'subscription_source' => SubscriptionSource::Local,
            'subscription_plan_code' => $planCode,
            'subscription_interval' => SubscriptionInterval::from($interval),
            'active_addon_codes' => $addonCodes,
            'subscription_period_starts_at' => now(),
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_meta' => $meta,
            'subscription_ends_at' => $durationDays > 0 ? now()->addDays($durationDays) : null,
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
