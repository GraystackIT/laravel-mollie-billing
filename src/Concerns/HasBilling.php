<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Concerns;

use Bavix\Wallet\Traits\HasWallets;
use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Casts\UtcDatetime;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialExtended;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ChangePlan;
use GraystackIT\MollieBilling\Services\Billing\DisableAddon;
use GraystackIT\MollieBilling\Services\Billing\EnableAddon;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default implementations for the Billable contract. Applications add `use HasBilling`
 * to their billable model and only override individual methods when column names differ.
 */
trait HasBilling
{
    use HasWallets {
        getWallet as protected bavixGetWallet;
        hasWallet as protected bavixHasWallet;
        createWallet as protected bavixCreateWallet;
    }

    /**
     * Case-insensitive wallet lookup. Walks the loaded wallets and matches by
     * slug regardless of casing — so callers can use `tokens` and the DB row
     * with slug `Tokens` is still returned. Falls back to the bavix default for
     * exact-match lookups.
     */
    public function getWallet(string $slug): ?\Bavix\Wallet\Models\Wallet
    {
        $exact = $this->bavixGetWallet($slug);
        if ($exact !== null) {
            return $exact;
        }

        foreach ($this->wallets as $wallet) {
            if (strcasecmp((string) $wallet->slug, $slug) === 0) {
                return $wallet;
            }
        }

        return null;
    }

    /**
     * Case-insensitive existence check.
     */
    public function hasWallet(string $slug): bool
    {
        return $this->getWallet($slug) !== null;
    }

    /**
     * Reuse an existing case-insensitive wallet if one matches the requested slug,
     * otherwise create a fresh wallet via the bavix default. Prevents duplicate
     * wallets when the same usage type is referenced with different casings.
     *
     * @param  array<string, mixed>  $data
     */
    public function createWallet(array $data): \Bavix\Wallet\Models\Wallet
    {
        $slug = (string) ($data['slug'] ?? $data['name'] ?? '');
        if ($slug !== '') {
            $existing = $this->getWallet($slug);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->bavixCreateWallet($data);
    }

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

    /**
     * IANA timezone used to render billing dates in the customer portal.
     * Defaults to the package-wide `mollie-billing.billing_timezone` config.
     * Override on the consuming model (e.g. read a `preferred_timezone`
     * column) to honor a per-user/per-tenant choice. Persistence and
     * computation always remain UTC regardless of this value.
     */
    public function getBillingTimezone(): string
    {
        $tz = config('mollie-billing.billing_timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
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

        return ! empty($meta['scheduled_change']);
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
            'subscription_period_starts_at' => UtcDatetime::class,
            'trial_ends_at' => UtcDatetime::class,
            'subscription_ends_at' => UtcDatetime::class,
            'subscription_status' => SubscriptionStatus::class,
            'active_addon_codes' => 'array',
            'subscription_meta' => 'array',
            'scheduled_change_at' => UtcDatetime::class,
            'allows_billing_overage' => 'bool',
            'vat_exempt' => 'bool',
            'country_mismatch_flagged_at' => UtcDatetime::class,
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
        $wallet = $this->getWallet($type);
        $balance = (int) ($wallet?->balanceInt ?? 0);
        $included = $this->includedBillingQuota($type);
        $purchased = $wallet !== null
            ? WalletUsageService::getPurchasedBalance($wallet)
            : 0;

        // Plan credits are consumed first. The plan-only balance is the wallet
        // balance minus any surviving purchased credits. Used = how much of the
        // plan quota has been consumed.
        $purchasedRemaining = WalletUsageService::computePurchasedRemaining($purchased, $balance);
        $planOnlyBalance = $balance - $purchasedRemaining;

        return max(0, $included - $planOnlyBalance);
    }

    public function remainingBillingQuota(string $type): int
    {
        // The full positive balance is available — this includes both the plan's
        // included quota and any extra credits from one-time product purchases.
        return max(0, $this->getWallet($type)?->balanceInt ?? 0);
    }

    public function purchasedBillingCredits(string $type): int
    {
        $wallet = $this->getWallet($type);
        if ($wallet === null) {
            return 0;
        }

        return WalletUsageService::computePurchasedRemaining(
            WalletUsageService::getPurchasedBalance($wallet),
            (int) $wallet->balanceInt,
        );
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
        $override = $this->subscription_meta['next_charge_date_override'] ?? null;
        if ($override !== null && $override !== '') {
            try {
                $parsed = \Carbon\Carbon::parse((string) $override);
                if ($parsed->isFuture()) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // fall through to the regular computation below
            }
        }

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

    // ── VAT validations (audit trail) ──

    public function vatValidations(): MorphMany
    {
        return $this->morphMany(BillingVatValidation::class, 'billable');
    }

    /**
     * The most recent VIES validation for the billable's *current* VAT number.
     *
     * Filters on `vat_number` so that swapping the VAT number to a new value
     * automatically invalidates the previous validation — `currentVatValidation()`
     * then returns null until the new number has been validated.
     *
     * Implemented as a method (not a relation) because the per-row filter on
     * `vat_number` cannot be expressed safely in an Eloquent eager-loadable
     * relation when batching multiple billables with different VAT numbers.
     */
    public function currentVatValidation(): ?BillingVatValidation
    {
        $vatNumber = $this->vat_number ?? null;
        if ($vatNumber === null || $vatNumber === '') {
            return null;
        }

        return $this->vatValidations()
            ->where('vat_number', $vatNumber)
            ->orderByDesc('checked_at')
            ->first();
    }

    /**
     * Whether reverse-charge applies for this billable: a persisted VIES
     * validation marked valid=true exists for the current VAT number.
     *
     * Drives UI price display (B2B sees net, B2C sees gross). The actual
     * VAT calculation in VatCalculationService::calculate() reads the same
     * state independently.
     */
    public function usesReverseCharge(): bool
    {
        return $this->currentVatValidation()?->valid === true;
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

    // ── One-time orders ──

    /**
     * @param  array<int, string>|null  $couponCodes  Multiple stackable coupon codes; takes precedence over $couponCode.
     * @return array{checkout_url:?string, payment_id:string}
     */
    public function purchaseOneTimeOrder(string $productCode, array $metadata = [], ?string $couponCode = null, ?array $couponCodes = null): array
    {
        $payload = [
            'product_code' => $productCode,
            'metadata' => $metadata,
        ];

        if ($couponCodes !== null && $couponCodes !== []) {
            $payload['coupon_codes'] = $couponCodes;
        } elseif ($couponCode !== null && $couponCode !== '') {
            $payload['coupon_code'] = $couponCode;
        }

        return app(StartOneTimeOrderCheckout::class)->handle($this, $payload);
    }

    /** @return array<int, string> All configured product codes. */
    public function allBillingProducts(): array
    {
        return app(SubscriptionCatalogInterface::class)->allProducts();
    }

    /** @return array<int, string> Product codes this billable has purchased (paid invoices). */
    public function boughtBillingProducts(): array
    {
        $invoices = $this->billingInvoices()
            ->where('invoice_kind', 'one_time_order')
            ->where('status', 'paid')
            ->pluck('line_items');

        $codes = [];
        foreach ($invoices as $lineItems) {
            foreach ((array) $lineItems as $item) {
                if (! empty($item['code'])) {
                    $codes[] = (string) $item['code'];
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return array<int, string> Product codes available for purchase (excludes onetimeonly already bought). */
    public function availableBillingProducts(): array
    {
        $catalog = app(SubscriptionCatalogInterface::class);
        $bought = $this->boughtBillingProducts();

        $available = [];
        foreach ($catalog->allProducts() as $code) {
            if ($catalog->productOneTimeOnly($code) && in_array($code, $bought, true)) {
                continue;
            }
            $available[] = $code;
        }

        return $available;
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

    /**
     * @param  array<int, string>|null  $couponCodes  Multiple stackable coupon codes; takes precedence over $couponCode.
     */
    public function enableBillingAddon(string $addonCode, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        app(EnableAddon::class)->handle($this, $addonCode, $couponCode, $couponCodes);
    }

    public function disableBillingAddon(string $addonCode): void
    {
        app(DisableAddon::class)->handle($this, $addonCode);
    }

    /**
     * @param  array<int, string>|null  $couponCodes  Multiple stackable coupon codes; takes precedence over $couponCode.
     */
    public function syncBillingSeats(int $seats, ?string $couponCode = null, ?array $couponCodes = null): void
    {
        app(SyncSeats::class)->handle($this, $seats, $couponCode, $couponCodes);
    }
}
