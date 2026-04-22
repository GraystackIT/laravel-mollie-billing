<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 500],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1000],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
        ],
    ]);
});

function makePurchasedTestBillable(string $plan = 'pro'): TestBillable
{
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'test@example.com']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'billing_country' => 'DE',
        'mollie_mandate_id' => 'mdt_test',
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b->refresh();
}

// ── computePurchasedRemaining ──────────────────────────────────────────────────

it('preserves all purchased credits when plan quota covers consumption', function (): void {
    // purchased=500, balance=800 (plan covered all consumption)
    expect(WalletUsageService::computePurchasedRemaining(500, 800))->toBe(500);
});

it('reduces purchased credits when plan quota is exhausted', function (): void {
    // purchased=500, balance=300 (200 purchased credits consumed)
    expect(WalletUsageService::computePurchasedRemaining(500, 300))->toBe(300);
});

it('returns zero purchased credits when balance is zero', function (): void {
    // purchased=500, balance=0 (all consumed)
    expect(WalletUsageService::computePurchasedRemaining(500, 0))->toBe(0);
});

it('returns zero purchased credits when balance is negative', function (): void {
    // purchased=500, balance=-100 (overage beyond all credits)
    expect(WalletUsageService::computePurchasedRemaining(500, -100))->toBe(0);
});

it('returns zero when no purchased credits exist', function (): void {
    expect(WalletUsageService::computePurchasedRemaining(0, 700))->toBe(0);
});

// ── purchased_balance meta tracking ────────────────────────────────────────────

it('tracks purchased balance in wallet meta', function (): void {
    $billable = makePurchasedTestBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 1000); // plan credit

    $wallet = $billable->getWallet('Tokens');
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(0);

    WalletUsageService::addPurchasedBalance($wallet, 500);
    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(500);

    WalletUsageService::addPurchasedBalance($wallet, 200);
    expect(WalletUsageService::getPurchasedBalance($wallet->refresh()))->toBe(700);
});

// ── resetAndCredit preserves purchased credits ─────────────────────────────────

it('preserves purchased credits through resetAndCredit when plan covered consumption', function (): void {
    $billable = makePurchasedTestBillable();
    $service = app(WalletUsageService::class);

    // Seed: 1000 plan + 500 purchased (deposited separately) = 1500 total
    $service->credit($billable, 'Tokens', 1000);
    $service->credit($billable, 'Tokens', 500, 'one_time_order:pack');
    $wallet = $billable->getWallet('Tokens');
    WalletUsageService::addPurchasedBalance($wallet, 500);

    // Consume 800 — all from plan quota, purchased untouched
    $service->debit($billable, 'Tokens', 800);
    expect((int) $wallet->refresh()->balanceInt)->toBe(700);

    // Reset for new period with 1000 plan quota
    $service->resetAndCredit($billable, 'Tokens', 1000, 'subscription_renewal');

    $wallet->refresh();
    // purchasedRemaining = min(500, 700) = 500 (all preserved)
    // new balance = 1000 (plan) + 500 (purchased) = 1500
    expect((int) $wallet->balanceInt)->toBe(1500);
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(500);
});

it('reduces purchased credits through resetAndCredit when plan was exhausted', function (): void {
    $billable = makePurchasedTestBillable();
    $service = app(WalletUsageService::class);

    // Seed: 1000 plan + 500 purchased = 1500 total
    $service->credit($billable, 'Tokens', 1000);
    $service->credit($billable, 'Tokens', 500, 'one_time_order:pack');
    $wallet = $billable->getWallet('Tokens');
    WalletUsageService::addPurchasedBalance($wallet, 500);

    // Consume 1300 — 1000 from plan + 300 from purchased
    $service->debit($billable, 'Tokens', 1300);
    expect((int) $wallet->refresh()->balanceInt)->toBe(200);

    // Reset for new period
    $service->resetAndCredit($billable, 'Tokens', 1000, 'subscription_renewal');

    $wallet->refresh();
    // purchasedRemaining = min(500, 200) = 200
    // new balance = 1000 + 200 = 1200
    expect((int) $wallet->balanceInt)->toBe(1200);
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(200);
});

it('zeroes purchased credits through resetAndCredit when all credits consumed', function (): void {
    $billable = makePurchasedTestBillable();
    $service = app(WalletUsageService::class);

    // Seed: 1000 plan + 500 purchased = 1500 total
    $service->credit($billable, 'Tokens', 1000);
    $service->credit($billable, 'Tokens', 500, 'one_time_order:pack');
    $wallet = $billable->getWallet('Tokens');
    WalletUsageService::addPurchasedBalance($wallet, 500);

    // Consume everything and go into overage
    $service->debit($billable, 'Tokens', 1600);
    expect((int) $wallet->refresh()->balanceInt)->toBe(-100);

    $service->resetAndCredit($billable, 'Tokens', 1000, 'subscription_renewal');

    $wallet->refresh();
    // purchasedRemaining = max(0, min(500, -100)) = 0
    expect((int) $wallet->balanceInt)->toBe(1000);
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(0);
});

it('preserves purchased credits when no plan credits exist (free plan)', function (): void {
    $billable = makePurchasedTestBillable();
    $service = app(WalletUsageService::class);

    // Only purchased credits, no plan quota
    $service->credit($billable, 'Tokens', 500, 'one_time_order:pack');
    $wallet = $billable->getWallet('Tokens');
    WalletUsageService::addPurchasedBalance($wallet, 500);

    // Consume 200
    $service->debit($billable, 'Tokens', 200);
    expect((int) $wallet->refresh()->balanceInt)->toBe(300);

    // Reset with 0 plan quota (e.g. downgrade to free)
    $service->resetAndCredit($billable, 'Tokens', 0, 'subscription_renewal');

    $wallet->refresh();
    // purchasedRemaining = min(500, 300) = 300
    // new balance = 0 + 300 = 300
    expect((int) $wallet->balanceInt)->toBe(300);
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(300);
});
