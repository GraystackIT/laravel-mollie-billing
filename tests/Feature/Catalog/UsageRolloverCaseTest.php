<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * Every other usage-type lookup in the catalog is case-insensitive
 * (includedUsage, usageOveragePrice, HasBilling::getWallet,
 * billing:check-config), so `usageRollover()` must be too. The type it receives
 * at renewal is the plan's `included_usages` key casing.
 */
beforeEach(function (): void {
    config()->set('mollie-billing.usage_rollover_fallback', false);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1200],
                'usage_overage_prices' => ['Tokens' => 5],
            ],
        ],
    ]);
});

it('finds a lowercase usage_types entry for an uppercase plan key', function (): void {
    // Plan declares `Tokens`, config declares `tokens` — the package deliberately
    // tolerates that everywhere else, so rollover must not silently fall back.
    config()->set('mollie-billing-plans.usage_types', ['tokens' => ['rollover' => true]]);

    expect(app(SubscriptionCatalogInterface::class)->usageRollover('Tokens'))->toBeTrue();
});

it('finds an uppercase usage_types entry for a lowercase plan key', function (): void {
    config()->set('mollie-billing-plans.usage_types', ['TOKENS' => ['rollover' => true]]);

    expect(app(SubscriptionCatalogInterface::class)->usageRollover('tokens'))->toBeTrue();
});

it('honours an explicit false regardless of casing', function (): void {
    config()->set('mollie-billing.usage_rollover_fallback', true);
    config()->set('mollie-billing-plans.usage_types', ['tokens' => ['rollover' => false]]);

    expect(app(SubscriptionCatalogInterface::class)->usageRollover('Tokens'))->toBeFalse();
});

it('still falls back for a usage type that is not configured at all', function (): void {
    config()->set('mollie-billing-plans.usage_types', ['tokens' => ['rollover' => true]]);

    expect(app(SubscriptionCatalogInterface::class)->usageRollover('SMS'))->toBeFalse();

    config()->set('mollie-billing.usage_rollover_fallback', true);
    expect(app(SubscriptionCatalogInterface::class)->usageRollover('SMS'))->toBeTrue();
});

it('tolerates a non-array usage_types entry', function (): void {
    config()->set('mollie-billing-plans.usage_types', ['tokens' => true]);

    expect(app(SubscriptionCatalogInterface::class)->usageRollover('Tokens'))->toBeFalse();
});

it('does not wipe carried-over purchased credits on renewal', function (): void {
    // The renewal path asks usageRollover() with the plan's key casing. A false
    // negative sent it down resetAndCredit(), which wiped the customer's
    // carried-over balance instead of adding the new quota on top.
    config()->set('mollie-billing-plans.usage_types', ['tokens' => ['rollover' => true]]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(30),
    ])->save();

    $catalog = app(SubscriptionCatalogInterface::class);
    $service = app(WalletUsageService::class);

    $service->credit($billable, 'Tokens', 250, 'one_time_order:pack');

    expect($catalog->usageRollover('Tokens'))->toBeTrue();

    // Mirror the renewal branch: rollover → credit on top, no reset.
    $service->credit($billable, 'Tokens', 100, 'subscription_renewal_rollover');

    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(350);
});
