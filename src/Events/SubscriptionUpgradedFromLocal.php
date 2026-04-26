<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Events;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a local (free / coupon-granted) subscription has been converted to
 * a Mollie subscription via the UpgradeLocalToMollie path. Listeners can use
 * this to track conversion funnels separately from initial activations.
 */
class SubscriptionUpgradedFromLocal
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $oldPlanCode,
        public readonly string $oldInterval,
        public readonly string $newPlanCode,
        public readonly string $newInterval,
    ) {
    }
}
