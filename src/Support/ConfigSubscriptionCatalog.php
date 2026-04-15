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

        return (int) ($this->plan($planCode)['intervals'][$interval]['included_usages'][$usageType] ?? 0);
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

    public function basePriceNet(string $planCode, string $interval): int
    {
        return (int) ($this->plan($planCode)['intervals'][$interval]['base_price_net'] ?? 0);
    }

    public function seatPriceNet(string $planCode, string $interval): ?int
    {
        $value = $this->plan($planCode)['intervals'][$interval]['seat_price_net'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function addonPriceNet(string $addonCode, string $interval): int
    {
        return (int) ($this->addon($addonCode)['intervals'][$interval]['price_net'] ?? 0);
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

    public function planName(string $planCode): ?string
    {
        return $this->plan($planCode)['name'] ?? null;
    }

    public function usageOveragePrice(string $planCode, ?string $interval, string $usageType): ?int
    {
        if ($interval === null) {
            return null;
        }

        $value = $this->plan($planCode)['intervals'][$interval]['usage_overage_prices'][$usageType] ?? null;

        return $value === null ? null : (int) $value;
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
}
