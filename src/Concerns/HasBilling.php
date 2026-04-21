<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Concerns;

use Bavix\Wallet\Traits\HasWallets;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialExtended;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ChangePlan;
use GraystackIT\MollieBilling\Services\Billing\DisableAddon;
use GraystackIT\MollieBilling\Services\Billing\EnableAddon;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default implementations for the Billable contract. Applications add `use HasBilling`
 * to their billable model and only override individual methods when column names differ.
 */
trait HasBilling
{
    use HasWallets;

    public function getBillingEmail(): string
    {
        return $this->email;
    }

    public function getBillingName(): string
    {
        return $this->name;
    }

    public function getBillingStreet(): ?string
    {
        return $this->billing_street;
    }

    public function getBillingCity(): ?string
    {
        return $this->billing_city;
    }

    public function getBillingPostalCode(): ?string
    {
        return $this->billing_postal_code;
    }

    public function getBillingCountry(): ?string
    {
        return $this->billing_country;
    }

    public function getBillingSubscriptionPlanCode(): ?string
    {
        return $this->subscription_plan_code;
    }

    public function getBillingSubscriptionInterval(): ?string
    {
        return $this->subscription_interval?->value;
    }

    public function getBillingSubscriptionSource(): ?string
    {
        return $this->subscription_source?->value;
    }

    public function getMollieMandateId(): ?string
    {
        return $this->mollie_mandate_id;
    }

    public function getMollieCustomerId(): ?string
    {
        return $this->mollie_customer_id;
    }

    public function getActiveBillingAddonCodes(): array
    {
        return $this->active_addon_codes ?? [];
    }

    public function getBillingSubscriptionMeta(): array
    {
        return $this->subscription_meta ?? [];
    }

    public function hasPendingBillingPlanChange(): bool
    {
        $meta = $this->getBillingSubscriptionMeta();

        return ! empty($meta['pending_plan_change']) || ! empty($meta['scheduled_change']);
    }

    public function getBillingPeriodStartsAt(): ?CarbonInterface
    {
        return $this->subscription_period_starts_at;
    }

    public function getBillingTrialEndsAt(): ?CarbonInterface
    {
        return $this->trial_ends_at;
    }

    public function getBillingSubscriptionEndsAt(): ?CarbonInterface
    {
        return $this->subscription_ends_at;
    }

    public function getBillingSubscriptionStatus(): SubscriptionStatus
    {
        return $this->subscription_status;
    }

    public function initializeHasBilling(): void
    {
        $this->mergeCasts([
            'subscription_source' => SubscriptionSource::class,
            'subscription_interval' => SubscriptionInterval::class,
            'subscription_period_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'subscription_status' => SubscriptionStatus::class,
            'active_addon_codes' => 'array',
            'subscription_meta' => 'array',
            'scheduled_change_at' => 'datetime',
            'allows_billing_overage' => 'bool',
            'tax_country_verified' => 'bool',
            'vat_exempt' => 'bool',
            'country_mismatch_flagged_at' => 'datetime',
        ]);
    }

    // ── Subscription predicates ──

    public function isLocalBillingSubscription(): bool
    {
        return $this->subscription_source === SubscriptionSource::Local;
    }

    public function hasMollieMandate(): bool
    {
        return $this->mollie_mandate_id !== null;
    }

    public function isOnBillingTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function hasExpiredBillingSubscription(): bool
    {
        return $this->subscription_ends_at !== null && $this->subscription_ends_at->isPast();
    }

    public function isBillingPastDue(): bool
    {
        return $this->subscription_status === SubscriptionStatus::PastDue;
    }

    public function isOnBillingPlan(string $planCode): bool
    {
        return $this->subscription_plan_code === $planCode;
    }

    public function isOnAnyBillingPlan(array $planCodes): bool
    {
        return in_array($this->subscription_plan_code, $planCodes, true);
    }

    public function hasAccessibleBillingSubscription(): bool
    {
        return match ($this->subscription_status) {
            SubscriptionStatus::Active => true,
            SubscriptionStatus::Trial => true,
            SubscriptionStatus::Cancelled => $this->subscription_ends_at?->isFuture() ?? false,
            SubscriptionStatus::PastDue, SubscriptionStatus::Expired => false,
            default => false,
        };
    }

    // ── Feature gating ──

    public function hasPlanFeature(string $feature): bool
    {
        return app(FeatureAccess::class)->has($this, $feature);
    }

    public function hasAllPlanFeatures(array $features): bool
    {
        return app(FeatureAccess::class)->hasAll($this, $features);
    }

    public function hasAnyPlanFeature(array $features): bool
    {
        return app(FeatureAccess::class)->hasAny($this, $features);
    }

    // ── Usage / metered billing ──

    public function recordBillingUsage(string $type, int $quantity = 1, ?string $reason = null): void
    {
        app(WalletUsageService::class)->debit($this, $type, $quantity, $reason);
    }

    public function creditBillingUsage(string $type, int $quantity = 1, ?string $reason = null): void
    {
        app(WalletUsageService::class)->credit($this, $type, $quantity, $reason);
    }

    public function includedBillingQuota(string $type): int
    {
        return app(SubscriptionCatalogInterface::class)->includedUsage(
            $this->getBillingSubscriptionPlanCode() ?? '',
            $this->getBillingSubscriptionInterval(),
            $type,
        );
    }

    public function usedBillingQuota(string $type): int
    {
        $balance = $this->getWallet($type)?->balanceInt ?? 0;
        $included = $this->includedBillingQuota($type);
        $catalog = app(SubscriptionCatalogInterface::class);
        $rollover = $catalog->usageRollover($this->getBillingSubscriptionPlanCode() ?? '');

        if (! $rollover) {
            // Without rollover, balance should never exceed included (capped).
            return max(0, $included - min($balance, $included));
        }

        return max(0, $included - $balance);
    }

    public function remainingBillingQuota(string $type): int
    {
        $balance = max(0, $this->getWallet($type)?->balanceInt ?? 0);
        $catalog = app(SubscriptionCatalogInterface::class);
        $rollover = $catalog->usageRollover($this->getBillingSubscriptionPlanCode() ?? '');

        if (! $rollover) {
            return min($balance, $this->includedBillingQuota($type));
        }

        return $balance;
    }

    public function billingOverageCount(string $type): int
    {
        $balance = $this->getWallet($type)?->balanceInt ?? 0;

        return $balance < 0 ? abs($balance) : 0;
    }

    public function hasBillingQuotaLeft(string $type): bool
    {
        return $this->hasMollieMandate() || $this->remainingBillingQuota($type) > 0;
    }

    public function billingOveragePrice(string $type): ?int
    {
        return app(SubscriptionCatalogInterface::class)->usageOveragePrice(
            $this->getBillingSubscriptionPlanCode() ?? '',
            $this->getBillingSubscriptionInterval(),
            $type,
        );
    }

    // ── Seats ──

    public function getBillingSeatCount(): int
    {
        return (int) ($this->getBillingSubscriptionMeta()['seat_count'] ?? $this->getIncludedBillingSeats());
    }

    public function getIncludedBillingSeats(): int
    {
        $planCode = $this->getBillingSubscriptionPlanCode();

        return $planCode ? app(SubscriptionCatalogInterface::class)->includedSeats($planCode) : 0;
    }

    public function getExtraBillingSeats(): int
    {
        return max(0, $this->getBillingSeatCount() - $this->getIncludedBillingSeats());
    }

    // getUsedBillingSeats() is intentionally NOT provided by this trait.
    // Each app MUST implement it on the billable model (e.g. return $this->users()->count()).

    // ── Addons ──

    /**
     * Quantity per addon. Default: 1 if active, 0 otherwise.
     * Apps with quantitative addons (e.g. "Storage × N GB") override this.
     */
    public function getBillingAddonQuantity(string $addonCode): int
    {
        return in_array($addonCode, $this->getActiveBillingAddonCodes(), true) ? 1 : 0;
    }

    // ── Overage policy ──

    public function allowsBillingOverage(): bool
    {
        if ($this->allows_billing_overage !== null) {
            return (bool) $this->allows_billing_overage;
        }

        return (bool) config('mollie-billing.allow_overage_default', true);
    }

    // ── Trial management (admin actions) ──

    public function extendBillingTrialUntil(CarbonInterface $until): void
    {
        $previous = $this->trial_ends_at;
        $newEnd = $previous && $previous->isAfter($until) ? $previous : $until;

        $this->forceFill(['trial_ends_at' => $newEnd])->save();

        if ($this->subscription_status !== SubscriptionStatus::Trial && $newEnd->isFuture()) {
            $this->forceFill(['subscription_status' => SubscriptionStatus::Trial])->save();
        }

        event(new TrialExtended($this, $previous, $newEnd));
    }

    // ── Invoices / history ──

    public function billingInvoices(): MorphMany
    {
        return $this->morphMany(BillingInvoice::class, 'billable')->orderByDesc('created_at');
    }

    public function latestBillingInvoice(): ?BillingInvoice
    {
        return $this->billingInvoices()->first();
    }

    public function nextBillingDate(): ?CarbonInterface
    {
        if ($this->subscription_period_starts_at === null || $this->subscription_interval === null) {
            return null;
        }

        return $this->subscription_interval === SubscriptionInterval::Yearly
            ? $this->subscription_period_starts_at->copy()->addYear()
            : $this->subscription_period_starts_at->copy()->addMonth();
    }

    public function totalBillingSpentGross(): int
    {
        return (int) $this->billingInvoices()
            ->whereIn('status', ['paid', 'refunded'])
            ->sum('amount_gross');
    }

    public function redeemedBillingCoupons(): MorphMany
    {
        return $this->morphMany(CouponRedemption::class, 'billable');
    }

    // ── Plan metadata ──

    public function getCurrentBillingPlanName(): ?string
    {
        $code = $this->getBillingSubscriptionPlanCode();

        return $code ? app(SubscriptionCatalogInterface::class)->planName($code) : null;
    }

    // ── URLs ──

    public function billingPortalUrl(): string
    {
        return route(BillingRoute::name('index'), $this->urlRouteParameters());
    }

    public function billingPlanChangeUrl(): string
    {
        return route(BillingRoute::name('plan'), $this->urlRouteParameters());
    }

    /**
     * Override in multi-tenant apps to inject tenant parameters, or register
     * a global resolver via MollieBilling::urlParametersUsing().
     *
     * @return array<string, mixed>
     */
    protected function urlRouteParameters(): array
    {
        /** @var \GraystackIT\MollieBilling\Contracts\Billable $this */
        return MollieBilling::resolveUrlParameters($this);
    }

    // ── Subscription management ──

    public function cancelBillingSubscription(bool $immediately = false): void
    {
        app(CancelSubscription::class)->handle($this, $immediately);
    }

    public function changeBillingPlan(string $planCode, string $interval): void
    {
        app(ChangePlan::class)->handle($this, $planCode, $interval);
    }

    public function resubscribeBillingPlan(): void
    {
        app(ResubscribeSubscription::class)->handle($this);
    }

    public function enableBillingAddon(string $addonCode): void
    {
        app(EnableAddon::class)->handle($this, $addonCode);
    }

    public function disableBillingAddon(string $addonCode): void
    {
        app(DisableAddon::class)->handle($this, $addonCode);
    }

    public function syncBillingSeats(int $seats): void
    {
        app(SyncSeats::class)->handle($this, $seats);
    }
}
