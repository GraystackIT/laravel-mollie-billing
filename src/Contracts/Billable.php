<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Contracts;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Billable
{
    // Contact / billing address
    public function getBillingEmail(): string;
    public function getBillingName(): string;
    public function setBillingName(string $name): void;
    public function getBillingStreet(): ?string;
    public function getBillingCity(): ?string;
    public function getBillingPostalCode(): ?string;
    public function getBillingCountry(): ?string;

    // IANA timezone for displaying billing dates in the customer portal.
    // Persistence and computation always remain UTC.
    public function getBillingTimezone(): string;

    // Subscription master data
    public function getBillingSubscriptionPlanCode(): ?string;
    public function getBillingSubscriptionInterval(): ?string;
    public function getBillingSubscriptionSource(): ?string;
    public function getBillingSubscriptionStatus(): SubscriptionStatus;
    public function getBillingSubscriptionMeta(): array;
    public function recordPendingFirstPayment(string $paymentId): void;
    public function getPendingFirstPaymentId(): ?string;
    public function clearPendingFirstPayment(): void;
    public function getActiveBillingAddonCodes(): array;
    public function getBillingPeriodStartsAt(): ?CarbonInterface;
    public function getBillingTrialEndsAt(): ?CarbonInterface;
    public function getBillingSubscriptionEndsAt(): ?CarbonInterface;

    // Mollie identifiers
    public function getMollieMandateId(): ?string;
    public function getMollieCustomerId(): ?string;
    public function hasMollieMandate(): bool;

    // Subscription predicates
    public function isLocalBillingSubscription(): bool;
    public function hasAccessibleBillingSubscription(): bool;
    public function hasExpiredBillingSubscription(): bool;
    public function isBillingPastDue(): bool;
    public function isOnBillingTrial(): bool;
    public function isOnBillingPlan(string $planCode): bool;
    public function isOnAnyBillingPlan(array $planCodes): bool;

    // Feature gating
    public function hasPlanFeature(string $feature): bool;
    public function hasAllPlanFeatures(array $features): bool;
    public function hasAnyPlanFeature(array $features): bool;

    // Usage / metered billing
    public function recordBillingUsage(string $type, int $quantity = 1, ?string $reason = null): void;
    public function creditBillingUsage(string $type, int $quantity = 1, ?string $reason = null): void;
    public function includedBillingQuota(string $type): int;
    public function usedBillingQuota(string $type): int;
    public function remainingBillingQuota(string $type): int;
    public function purchasedBillingCredits(string $type): int;
    public function billingOverageCount(string $type): int;
    public function billingOveragePrice(string $type): ?int;
    public function hasBillingQuotaLeft(string $type): bool;

    // Seats
    public function getBillingSeatCount(): int;
    public function getIncludedBillingSeats(): int;
    public function getExtraBillingSeats(): int;
    public function getUsedBillingSeats(): int;
    public function getAvailableBillingSeats(): int;
    public function isBillingSeatAvailable(int $count = 1): bool;

    // Addons
    public function getBillingAddonQuantity(string $addonCode): int;

    // Overage policy
    public function allowsBillingOverage(): bool;

    // Trial management (admin actions)
    public function extendBillingTrialUntil(CarbonInterface $until): void;

    // Invoices / history
    public function billingInvoices(): MorphMany;
    public function latestBillingInvoice(): ?BillingInvoice;
    public function nextBillingDate(): ?CarbonInterface;
    public function totalBillingSpentGross(): int;
    public function redeemedBillingCoupons(): MorphMany;

    // Plan metadata (human-readable, for UI)
    public function getCurrentBillingPlanName(): ?string;

    // URLs (convenience for UI links)
    public function billingPortalUrl(): string;
    public function billingPlanChangeUrl(): string;

    // One-time orders
    /** @return array{checkout_url:?string, payment_id:string} */
    public function purchaseOneTimeOrder(string $productCode, array $metadata = [], ?string $couponCode = null, ?array $couponCodes = null): array;

    /** @return array<int, string> */
    public function allBillingProducts(): array;

    /** @return array<int, string> */
    public function boughtBillingProducts(): array;

    /** @return array<int, string> */
    public function availableBillingProducts(): array;

    // Eloquent scope hook. Applied as a global scope by HasBilling so every
    // package-side query that walks the billable model (admin listings, KPIs,
    // lifecycle jobs) sees the same filtered set. Default is a no-op — apps
    // override on multi-purpose user models to exclude rows that share the
    // table but are not actually billables (e.g. staff users under a tenant).
    public function applyBillingScope(Builder $query): void;

    // Subscription management (actions)
    public function cancelBillingSubscription(bool $immediately = false): void;
    public function changeBillingPlan(string $planCode, string $interval): void;
    public function resubscribeBillingPlan(): void;
    public function enableBillingAddon(string $addonCode, ?string $couponCode = null, ?array $couponCodes = null): void;
    public function disableBillingAddon(string $addonCode): void;
    public function syncBillingSeats(int $seats, ?string $couponCode = null, ?array $couponCodes = null): void;
}
