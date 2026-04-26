<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Contracts;

interface SubscriptionCatalogInterface
{
    /**
     * Included usage quota for a specific type within a plan+interval.
     *
     * Note: usage quotas are plan-scoped only. Addons do not contribute
     * included usages. If addon-based quotas are needed in the future,
     * a dedicated method (e.g. addonIncludedUsages) should be added, and
     * UpdateSubscription::adjustWalletsForPlanChange() must be updated to
     * call it when addons change — currently wallet adjustment only runs
     * on plan or interval changes.
     */
    public function includedUsage(string $planCode, ?string $interval, string $usageType): int;

    /**
     * All included usage quotas for a plan+interval.
     *
     * @see includedUsage() for scoping notes — addons are excluded.
     *
     * @return array<string, int>
     */
    public function includedUsages(string $planCode, ?string $interval): array;

    public function planAllowsAddon(string $planCode, string $addonCode): bool;

    public function basePriceNet(string $planCode, string $interval): int;

    public function seatPriceNet(string $planCode, string $interval): ?int;

    /**
     * Whether the plan is free in the given interval — base price 0 and no extra seats sold.
     *
     * Used to decide whether a Mollie subscription is needed at all (free → Local source)
     * and to gate the Local→Mollie upgrade path in the plan-change UI.
     */
    public function isFreePlan(string $planCode, string $interval): bool;

    public function addonPriceNet(string $addonCode, string $interval): int;

    public function includedSeats(string $planCode): int;

    /** @return array<int, string> */
    public function planFeatures(string $planCode): array;

    /** @return array<int, string> */
    public function addonFeatures(string $addonCode): array;

    /** @return array<int, string> */
    public function allPlans(): array;

    /** @return array<int, string> */
    public function allAddons(): array;

    /** @return array<int, string> */
    public function allUsageTypes(): array;

    public function planName(string $planCode): ?string;

    public function planDescription(string $planCode): ?string;

    public function addonName(string $addonCode): ?string;

    public function addonDescription(string $addonCode): ?string;

    public function usageTypeName(string $usageType): string;

    public function usageOveragePrice(string $planCode, ?string $interval, string $usageType): ?int;

    public function usageRollover(string $planCode): bool;

    public function yearlySavingsPercent(string $planCode): float;

    public function featureName(string $featureKey): ?string;

    public function featureDescription(string $featureKey): ?string;

    /** @return array<string, array{name: string, description: ?string}> */
    public function allFeatures(): array;

    // One-time products

    /** @return array<int, string> */
    public function allProducts(): array;

    /** @return array<string, mixed> */
    public function product(string $productCode): array;

    public function productName(string $productCode): ?string;

    public function productDescription(string $productCode): ?string;

    public function productImageUrl(string $productCode): ?string;

    public function productPriceNet(string $productCode): int;

    public function productUsageType(string $productCode): ?string;

    public function productQuantity(string $productCode): ?int;

    public function productOneTimeOnly(string $productCode): bool;

    public function productGroup(string $productCode): ?string;

    public function productGroupName(string $groupKey): ?string;

    public function productGroupSort(string $groupKey): int;
}
