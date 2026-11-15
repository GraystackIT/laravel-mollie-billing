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

    /**
     * Whether the plan is actually sold in this interval.
     *
     * A plan may offer only one interval — that is valid configuration, and
     * `billing:check-config` accepts it. This is the question the price getters
     * cannot answer: `basePriceNet()` returns `0` for an interval that does not
     * exist, which is indistinguishable from a genuinely free plan. Every surface
     * that lets a customer pick an interval must ask here first, or a paid plan
     * priced at 0 gets activated for free.
     *
     * @see addonOffersInterval() for the same question about an addon
     */
    public function planOffersInterval(string $planCode, string $interval): bool;

    /**
     * Whether the addon is actually sold in this interval.
     *
     * Same rationale as {@see planOffersInterval()}: `addonPriceNet()` answers 0
     * for an interval the addon does not define, which would enable it for free.
     */
    public function addonOffersInterval(string $addonCode, string $interval): bool;

    /**
     * Base price for a (plan, interval), in minor units.
     *
     * Returns `0` both for a free plan and for an interval the plan does not
     * offer. Call {@see planOffersInterval()} first whenever the interval comes
     * from user input — this getter cannot tell you which of the two it is.
     */
    public function basePriceNet(string $planCode, string $interval): int;

    public function seatPriceNet(string $planCode, string $interval): ?int;

    /**
     * Trial-period length in days for a (plan, interval). Strictly per-interval —
     * if the value is missing for an interval, there is no trial for that interval.
     * Always returns >= 0; negative or non-integer values are clamped to 0.
     */
    public function trialDays(string $planCode, string $interval): int;

    /**
     * Whether the plan is free in the given interval — base price 0 and no extra seats sold.
     *
     * Used to decide whether a Mollie subscription is needed at all (free → Local source)
     * and to gate the Local→Mollie upgrade path in the plan-change UI.
     */
    public function isFreePlan(string $planCode, string $interval): bool;

    public function addonPriceNet(string $addonCode, string $interval): int;

    /**
     * The addon's price per seat for this interval, or null when it has none.
     *
     * Charged on top of the base price ({@see addonPriceNet()}) for every seat of the
     * subscription beyond {@see addonIncludedSeats()} — the plan's own included seats
     * count here like any other seat. Null is "this addon is not priced per seat" and
     * leaves it exactly as an addon was before per-seat pricing existed.
     */
    public function addonSeatPriceNet(string $addonCode, string $interval): ?int;

    /**
     * Seats the addon's per-seat price does not charge for. Independent of the plan's
     * included seats; 0 by default.
     */
    public function addonIncludedSeats(string $addonCode): int;

    /**
     * Whether the addon bills nothing in this interval — neither a base price nor a
     * per-seat price.
     *
     * The one question "may this be enabled without a payment instrument?" has to ask.
     * `addonPriceNet() > 0` answered it while the base price was the only price; an
     * addon with base 0 and a seat price would read as free and could be switched on
     * for a Local subscription that nothing can bill.
     */
    public function addonIsFree(string $addonCode, string $interval): bool;

    public function includedSeats(string $planCode): int;

    /** @return array<int, string> */
    public function planFeatures(string $planCode): array;

    /** @return array<int, string> */
    public function addonFeatures(string $addonCode): array;

    /**
     * Every plan code, including hidden ones.
     *
     * This is a lookup-scope listing — admin pickers (access grants, coupon
     * restrictions, bulk filters) rely on hidden plans being present. For
     * anything a customer chooses from, use visiblePlans().
     *
     * @return array<int, string>
     */
    public function allPlans(): array;

    /**
     * Plan codes that may be offered to customers — everything except hidden plans.
     *
     * Hidden means unlisted, never unresolvable: a hidden plan still resolves
     * through every by-code lookup (planName(), planFeatures(), basePriceNet(), …)
     * so a subscriber sitting on one has their plan rendered like any other.
     *
     * @return array<int, string>
     */
    public function visiblePlans(): array;

    /**
     * Whether the plan is excluded from customer-facing listings.
     *
     * Internal, supplier, grandfathered and legacy plans set this. Checkout and
     * the portal's plan-change screen filter on it, and it is enforced
     * server-side as a change target by ValidateSubscriptionChange.
     */
    public function planIsHidden(string $planCode): bool;

    /** @return array<int, string> */
    public function allAddons(): array;

    /** @return array<int, string> */
    public function allUsageTypes(): array;

    public function planName(string $planCode): ?string;

    public function planDescription(string $planCode): ?string;

    public function addonName(string $addonCode): ?string;

    public function addonDescription(string $addonCode): ?string;

    public function usageTypeName(string $usageType): string;

    /**
     * Overage price per unit for a (plan, interval, usageType), or null when no
     * overage can be charged.
     *
     * Null covers both "no price configured" and the explicit `overage => false`
     * declaration — the two are behaviourally identical, the latter only exists
     * so `billing:check-config` can tell a deliberate no-overage quota apart
     * from a forgotten price.
     */
    public function usageOveragePrice(string $planCode, ?string $interval, string $usageType): ?int;

    public function usageRollover(string $usageType): bool;

    public function yearlySavingsPercent(string $planCode): float;

    public function featureName(string $featureKey): ?string;

    public function featureDescription(string $featureKey): ?string;

    /**
     * Whether a feature is shown where plans and addons are presented to the customer
     * (checkout plan cards, the plan-change screen, addon cards).
     *
     * A hidden feature is fully effective — `FeatureAccess`, `@planFeature` and the
     * `billing.feature` middleware resolve it like any other. It is only left out of
     * the lists a customer reads. That is what makes technical feature codes possible:
     * a flag an app gates on, which is not a selling point and has no place on a plan card.
     *
     * An unknown key is visible: a plan referencing a feature nobody defined is a
     * misconfiguration `billing:check-config` reports, not something to hide.
     */
    public function featureIsVisible(string $featureKey): bool;

    /**
     * The plan's features a customer is shown, in the plan's order.
     *
     * @return array<int, string>
     */
    public function visiblePlanFeatures(string $planCode): array;

    /**
     * The addon's features a customer is shown, in the addon's order.
     *
     * @return array<int, string>
     */
    public function visibleAddonFeatures(string $addonCode): array;

    /** @return array<string, array{name: string, description: ?string, visible: bool}> */
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
