<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

class ConfigSubscriptionCatalog implements SubscriptionCatalogInterface
{
    public function __construct(private readonly string $configKey = 'mollie-billing-plans')
    {
    }

    public function includedUsage(string $planCode, ?string $interval, string $usageType): int
    {
        if ($interval === null) {
            return 0;
        }

        $usages = (array) ($this->plan($planCode)['intervals'][$interval]['included_usages'] ?? []);

        return (int) ($this->lookupCaseInsensitive($usages, $usageType) ?? 0);
    }

    public function includedUsages(string $planCode, ?string $interval): array
    {
        if ($interval === null) {
            return [];
        }

        $values = (array) ($this->plan($planCode)['intervals'][$interval]['included_usages'] ?? []);

        return array_map(static fn ($v) => (int) $v, $values);
    }

    public function planAllowsAddon(string $planCode, string $addonCode): bool
    {
        return in_array($addonCode, $this->plan($planCode)['allowed_addons'] ?? [], true);
    }

    public function planOffersInterval(string $planCode, string $interval): bool
    {
        // `base_price_net` is the one key every priced interval must carry (a free
        // plan sets it to 0 explicitly), so its presence is what separates
        // "sold at 0" from "not sold at all".
        return array_key_exists(
            'base_price_net',
            (array) ($this->plan($planCode)['intervals'][$interval] ?? []),
        );
    }

    public function addonOffersInterval(string $addonCode, string $interval): bool
    {
        return array_key_exists(
            'price_net',
            (array) ($this->addon($addonCode)['intervals'][$interval] ?? []),
        );
    }

    public function basePriceNet(string $planCode, string $interval): int
    {
        return (int) ($this->plan($planCode)['intervals'][$interval]['base_price_net'] ?? 0);
    }

    public function seatPriceNet(string $planCode, string $interval): ?int
    {
        $value = $this->plan($planCode)['intervals'][$interval]['seat_price_net'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function trialDays(string $planCode, string $interval): int
    {
        $value = $this->plan($planCode)['intervals'][$interval]['trial_days'] ?? null;

        return is_int($value) ? max(0, $value) : 0;
    }

    public function isFreePlan(string $planCode, string $interval): bool
    {
        return $this->basePriceNet($planCode, $interval) === 0
            && $this->seatPriceNet($planCode, $interval) === null;
    }

    public function addonPriceNet(string $addonCode, string $interval): int
    {
        return (int) ($this->addon($addonCode)['intervals'][$interval]['price_net'] ?? 0);
    }

    public function addonSeatPriceNet(string $addonCode, string $interval): ?int
    {
        $value = $this->addon($addonCode)['intervals'][$interval]['seat_price_net'] ?? null;

        return $value === null ? null : max(0, (int) $value);
    }

    public function addonIncludedSeats(string $addonCode): int
    {
        return max(0, (int) ($this->addon($addonCode)['included_seats'] ?? 0));
    }

    public function addonIsFree(string $addonCode, string $interval): bool
    {
        return $this->addonPriceNet($addonCode, $interval) <= 0
            && (int) ($this->addonSeatPriceNet($addonCode, $interval) ?? 0) <= 0;
    }

    public function includedSeats(string $planCode): int
    {
        return (int) ($this->plan($planCode)['included_seats'] ?? 1);
    }

    public function planFeatures(string $planCode): array
    {
        return (array) ($this->plan($planCode)['feature_keys'] ?? []);
    }

    public function addonFeatures(string $addonCode): array
    {
        return (array) ($this->addon($addonCode)['feature_keys'] ?? []);
    }

    public function allPlans(): array
    {
        return array_keys(config($this->configKey.'.plans', []));
    }

    public function visiblePlans(): array
    {
        return array_values(array_filter(
            $this->allPlans(),
            fn (string $code): bool => ! $this->planIsHidden($code),
        ));
    }

    public function planIsHidden(string $planCode): bool
    {
        return (bool) ($this->plan($planCode)['hidden'] ?? false);
    }

    public function allAddons(): array
    {
        return array_keys((array) config($this->configKey.'.addons', []));
    }

    public function allUsageTypes(): array
    {
        $types = [];
        foreach ((array) config($this->configKey.'.plans', []) as $plan) {
            foreach ((array) ($plan['intervals'] ?? []) as $interval) {
                foreach (array_keys((array) ($interval['included_usages'] ?? [])) as $type) {
                    $types[$type] = true;
                }
                foreach (array_keys((array) ($interval['usage_overage_prices'] ?? [])) as $type) {
                    $types[$type] = true;
                }
            }
        }

        return array_values(array_map('strval', array_keys($types)));
    }

    public function planName(string $planCode): ?string
    {
        $transKey = 'billing::plans.'.$planCode.'.name';
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return $this->plan($planCode)['name'] ?? null;
    }

    public function planDescription(string $planCode): ?string
    {
        $transKey = 'billing::plans.'.$planCode.'.description';
        if (trans()->has($transKey)) {
            $value = (string) trans($transKey);

            return $value === '' ? null : $value;
        }

        $value = $this->plan($planCode)['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function addonName(string $addonCode): ?string
    {
        $transKey = 'billing::addons.'.$addonCode.'.name';
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return $this->addon($addonCode)['name'] ?? null;
    }

    public function addonDescription(string $addonCode): ?string
    {
        $transKey = 'billing::addons.'.$addonCode.'.description';
        if (trans()->has($transKey)) {
            $value = (string) trans($transKey);

            return $value === '' ? null : $value;
        }

        $value = $this->addon($addonCode)['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function usageTypeName(string $usageType): string
    {
        $transKey = 'billing::usages.'.$usageType;
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return ucfirst($usageType);
    }

    public function usageOveragePrice(string $planCode, ?string $interval, string $usageType): ?int
    {
        if ($interval === null) {
            return null;
        }

        $prices = (array) ($this->plan($planCode)['intervals'][$interval]['usage_overage_prices'] ?? []);
        $value = $this->lookupCaseInsensitive($prices, $usageType);

        // `false` is the explicit "this quota has no overage" declaration. It must
        // resolve to null, not 0: a 0 price would render as "€0.00 per unit" in the
        // checkout and plan-change usage lists.
        if ($value === null || $value === false) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Case-insensitive array lookup. Returns the first value whose key matches
     * `$needle` regardless of casing, or null if nothing matches. Lets callers
     * use any consistent casing (`tokens` / `Tokens` / `TOKENS`) without forcing
     * the package config to a specific style.
     *
     * @param  array<string, mixed>  $haystack
     */
    private function lookupCaseInsensitive(array $haystack, string $needle): mixed
    {
        if (array_key_exists($needle, $haystack)) {
            return $haystack[$needle];
        }

        foreach ($haystack as $key => $value) {
            if (strcasecmp((string) $key, $needle) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether unused quota of this usage type carries over into the next period.
     *
     * Looked up case-insensitively, like every other usage-type lookup here:
     * the type reaching this method is the plan's `included_usages` key, so a
     * plan declaring `['Tokens' => 1000]` against
     * `usage_types => ['tokens' => ['rollover' => true]]` must still find its
     * entry — otherwise the fallback silently wipes carried-over credits.
     */
    public function usageRollover(string $usageType): bool
    {
        $entry = $this->lookupCaseInsensitive(
            (array) config("{$this->configKey}.usage_types", []),
            $usageType,
        );

        $typeValue = is_array($entry) ? ($entry['rollover'] ?? null) : null;

        if (is_bool($typeValue)) {
            return $typeValue;
        }

        return (bool) config('mollie-billing.usage_rollover_fallback', false);
    }

    public function yearlySavingsPercent(string $planCode): float
    {
        $plan = $this->plan($planCode);
        $monthly = (int) ($plan['intervals']['monthly']['base_price_net'] ?? 0);
        $yearly = (int) ($plan['intervals']['yearly']['base_price_net'] ?? 0);

        if ($monthly === 0) {
            return 0.0;
        }

        $monthlyAnnualized = $monthly * 12;

        if ($monthlyAnnualized <= 0) {
            return 0.0;
        }

        return round(($monthlyAnnualized - $yearly) / $monthlyAnnualized * 100, 2);
    }

    public function featureName(string $featureKey): ?string
    {
        $transKey = 'billing::features.'.$featureKey.'.name';
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return $this->feature($featureKey)['name'] ?? null;
    }

    public function featureDescription(string $featureKey): ?string
    {
        $transKey = 'billing::features.'.$featureKey.'.description';
        if (trans()->has($transKey)) {
            $value = (string) trans($transKey);

            return $value === '' ? null : $value;
        }

        $configDescription = $this->feature($featureKey)['description'] ?? null;

        return is_string($configDescription) && $configDescription !== '' ? $configDescription : null;
    }

    public function featureIsVisible(string $featureKey): bool
    {
        // Only an explicit `false` hides. Anything else — absent, null, a typo —
        // leaves the feature where it was: on the plan card.
        return ($this->feature($featureKey)['visible'] ?? true) !== false;
    }

    public function visiblePlanFeatures(string $planCode): array
    {
        return array_values(array_filter(
            array_map('strval', $this->planFeatures($planCode)),
            fn (string $key): bool => $this->featureIsVisible($key),
        ));
    }

    public function visibleAddonFeatures(string $addonCode): array
    {
        return array_values(array_filter(
            array_map('strval', $this->addonFeatures($addonCode)),
            fn (string $key): bool => $this->featureIsVisible($key),
        ));
    }

    public function allFeatures(): array
    {
        $keys = array_keys((array) config($this->configKey.'.features', []));

        $result = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $result[$key] = [
                'name' => $this->featureName($key),
                'description' => $this->featureDescription($key),
                'visible' => $this->featureIsVisible($key),
            ];
        }

        return $result;
    }

    public function allProducts(): array
    {
        return array_keys((array) config($this->configKey.'.products', []));
    }

    public function product(string $productCode): array
    {
        return $this->productConfig($productCode);
    }

    public function productName(string $productCode): ?string
    {
        $transKey = 'billing::products.'.$productCode.'.name';
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return $this->productConfig($productCode)['name'] ?? null;
    }

    public function productDescription(string $productCode): ?string
    {
        $transKey = 'billing::products.'.$productCode.'.description';
        if (trans()->has($transKey)) {
            $value = (string) trans($transKey);

            return $value === '' ? null : $value;
        }

        $value = $this->productConfig($productCode)['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function productImageUrl(string $productCode): ?string
    {
        $value = $this->productConfig($productCode)['image_url'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function productPriceNet(string $productCode): int
    {
        return (int) ($this->productConfig($productCode)['price_net'] ?? 0);
    }

    public function productUsageType(string $productCode): ?string
    {
        $value = $this->productConfig($productCode)['usage_type'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function productQuantity(string $productCode): ?int
    {
        $value = $this->productConfig($productCode)['quantity'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function productOneTimeOnly(string $productCode): bool
    {
        return (bool) ($this->productConfig($productCode)['onetimeonly'] ?? false);
    }

    public function productGroup(string $productCode): ?string
    {
        $value = $this->productConfig($productCode)['group'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function productGroupName(string $groupKey): ?string
    {
        $transKey = 'billing::product_groups.'.$groupKey;
        if (trans()->has($transKey)) {
            return (string) trans($transKey);
        }

        return $this->productGroupConfig($groupKey)['name'] ?? null;
    }

    public function productGroupSort(string $groupKey): int
    {
        return (int) ($this->productGroupConfig($groupKey)['sort'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function feature(string $key): array
    {
        return (array) config($this->configKey.'.features.'.$key, []);
    }

    /** @return array<string, mixed> */
    private function plan(string $code): array
    {
        return (array) config($this->configKey.'.plans.'.$code, []);
    }

    /** @return array<string, mixed> */
    private function addon(string $code): array
    {
        return (array) config($this->configKey.'.addons.'.$code, []);
    }

    /** @return array<string, mixed> */
    private function productConfig(string $code): array
    {
        return (array) config($this->configKey.'.products.'.$code, []);
    }

    /** @return array<string, mixed> */
    private function productGroupConfig(string $key): array
    {
        return (array) config($this->configKey.'.product_groups.'.$key, []);
    }
}
