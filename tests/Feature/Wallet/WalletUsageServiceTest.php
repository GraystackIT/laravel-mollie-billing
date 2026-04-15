<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\UsageLimitReached;
use GraystackIT\MollieBilling\Events\WalletCredited;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['emails' => 100],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['emails' => 1200],
            ],
        ],
    ]);
});

function makeMollieBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'mollie_mandate_id' => 'mdt_test_1',
        'mollie_customer_id' => 'cst_test_1',
        'allows_billing_overage' => true,
    ])->save();

    return $b->refresh();
}

function makeLocalBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Local', 'email' => 'l@b.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
    ])->save();

    return $b->refresh();
}

it('debits a wallet and reduces the balance', function (): void {
    $billable = makeMollieBillable();
    app(WalletUsageService::class)->credit($billable, 'emails', 100);

    app(WalletUsageService::class)->debit($billable, 'emails', 30);

    expect($billable->getWallet('emails')->refresh()->balanceInt)->toBe(70);
});

it('throws UsageLimitExceededException at hard-cap when no mandate', function (): void {
    $billable = makeLocalBillable();
    app(WalletUsageService::class)->credit($billable, 'emails', 5);

    expect(fn () => app(WalletUsageService::class)->debit($billable, 'emails', 10))
        ->toThrow(UsageLimitExceededException::class);
});

it('fires UsageLimitReached when hard-cap is hit', function (): void {
    Event::fake([UsageLimitReached::class]);

    $billable = makeLocalBillable();
    app(WalletUsageService::class)->credit($billable, 'emails', 2);

    try {
        app(WalletUsageService::class)->debit($billable, 'emails', 5);
    } catch (UsageLimitExceededException) {
        // expected
    }

    Event::assertDispatched(UsageLimitReached::class);
});

it('credits a wallet and dispatches WalletCredited', function (): void {
    Event::fake([WalletCredited::class]);

    $billable = makeMollieBillable();

    app(WalletUsageService::class)->credit($billable, 'emails', 25);

    expect($billable->getWallet('emails')->refresh()->balanceInt)->toBe(25);
    Event::assertDispatched(WalletCredited::class);
});
