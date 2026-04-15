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

    /** @return array<int, string> */
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
