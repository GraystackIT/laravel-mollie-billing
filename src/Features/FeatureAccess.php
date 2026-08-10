<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Features;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

class FeatureAccess
{
    public function __construct(private readonly SubscriptionCatalogInterface $catalog)
    {
    }

    public function has(Billable $billable, string $feature): bool
    {
        return in_array($feature, $this->resolveFeatures($billable), true);
    }

    public function hasAll(Billable $billable, array $features): bool
    {
        return empty(array_diff($features, $this->resolveFeatures($billable)));
    }

    public function hasAny(Billable $billable, array $features): bool
    {
        return (bool) array_intersect($features, $this->resolveFeatures($billable));
    }

    /**
     * Deliberately a catalog question — "what does this plan and its addons
     * include" — and not an access decision. Route access is governed by
     * `RequireActiveSubscription`; an expired subscription loses its features
     * because the expiry transition clears the plan code, not because this
     * resolver second-guesses the subscription state. Consuming apps use
     * `hasPlanFeature()` to gate navigation and modules, so mixing the two here
     * turns every unsubscribed-but-plan-assigned billable dark.
     *
     * @return array<int, string>
     */
    public function resolveFeatures(Billable $billable): array
    {
        $planCode = $billable->getBillingSubscriptionPlanCode();

        $features = $planCode ? $this->catalog->planFeatures($planCode) : [];

        foreach ($billable->getActiveBillingAddonCodes() as $addonCode) {
            $features = array_merge($features, $this->catalog->addonFeatures($addonCode));
        }

        return array_values(array_unique($features));
    }
}
