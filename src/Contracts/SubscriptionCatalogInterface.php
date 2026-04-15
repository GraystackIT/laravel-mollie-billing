<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Contracts;

interface SubscriptionCatalogInterface
{
    public function includedUsage(string $planCode, ?string $interval, string $usageType): int;

    /** @return array<string, int> */
    public function includedUsages(string $planCode, ?string $interval): array;

    public function planAllowsAddon(string $planCode, string $addonCode): bool;

    public function basePriceNet(string $planCode, string $interval): int;

    public function seatPriceNet(string $planCode, string $interval): ?int;

    public function addonPriceNet(string $addonCode, string $interval): int;

    public function includedSeats(string $planCode): int;

    /** @return array<int, string> */
    public function planFeatures(string $planCode): array;

    /** @return array<int, string> */
    public function addonFeatures(string $addonCode): array;

    /** @return array<int, string> */
    public function allPlans(): array;

    public function planName(string $planCode): ?string;

    public function usageOveragePrice(string $planCode, ?string $interval, string $usageType): ?int;

    public function yearlySavingsPercent(string $planCode): float;
}
