<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\WalletReset;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingPolicy;
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
                'included_usages' => ['Tokens' => 100],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 1200],
                'usage_overage_prices' => ['Tokens' => 10],
            ],
        ],
    ]);
});

function makeTestBillable(string $source = 'local'): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $b->forceFill([
        'subscription_source' => $source === 'mollie' ? SubscriptionSource::Mollie : SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'mollie_mandate_id' => $source === 'mollie' ? 'mdt_test' : null,
        'mollie_customer_id' => $source === 'mollie' ? 'cst_test' : null,
    ])->save();

    return $b->refresh();
}

// ── resetAndCredit ──────────────────────────────────────────────────────────

it('resetAndCredit resets positive balance to quota', function (): void {
    Event::fake([WalletReset::class]);
    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);

    $service->credit($billable, 'Tokens', 80);
    expect($billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(80);

    $service->resetAndCredit($billable, 'Tokens', 100);
    expect($billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(100);
    Event::assertDispatched(WalletReset::class, fn (WalletReset $e) => $e->previousBalance === 80 && $e->newBalance === 100);
});

it('resetAndCredit resets negative balance to quota', function (): void {
    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);

    $service->credit($billable, 'Tokens', 20);
    $billable->getWallet('Tokens')->forceWithdraw(50); // balance = -30
    expect($billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(-30);

    $service->resetAndCredit($billable, 'Tokens', 100);
    expect($billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(100);
});

it('resetAndCredit resets zero balance to quota', function (): void {
    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);

    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    expect((int) $wallet->balanceInt)->toBe(0);

    $service->resetAndCredit($billable, 'Tokens', 100);
    expect($billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(100);
});

// ── computeUsageOverageForPlanChange ────────────────────────────────────────

it('computes zero excess when usage is within prorated quota', function (): void {
    $periodStart = now()->subDays(15);
    $periodEnd = now()->addDays(15);

    // 1000 included, 300 used (balance=700), elapsed 50%, prorated=500
    // used (300) <= prorated (500) → excess = 0
    $result = BillingPolicy::computeUsageOverageForPlanChange(1000, 700, $periodStart, $periodEnd);

    expect($result['excess'])->toBe(0);
    expect($result['prorated_old_quota'])->toBe(500);
});

it('computes excess when usage exceeds prorated quota', function (): void {
    $periodStart = now()->subDays(15);
    $periodEnd = now()->addDays(15);

    // 1000 included, all used (balance=0), elapsed 50%, prorated=500
    // used (1000) - prorated (500) = excess 500
    $result = BillingPolicy::computeUsageOverageForPlanChange(1000, 0, $periodStart, $periodEnd);

    expect($result['excess'])->toBe(500);
    expect($result['prorated_old_quota'])->toBe(500);
});

it('computes excess for negative balance (overage)', function (): void {
    $periodStart = now()->subDays(15);
    $periodEnd = now()->addDays(15);

    // 1000 included, 1100 used (balance=-100), elapsed 50%, prorated=500
    // used (1100) - prorated (500) = excess 600
    $result = BillingPolicy::computeUsageOverageForPlanChange(1000, -100, $periodStart, $periodEnd);

    expect($result['excess'])->toBe(600);
});

it('computes zero excess when rollover credits cover the prorated quota', function (): void {
    $periodStart = now()->subDays(15);
    $periodEnd = now()->addDays(15);

    // 100 included, balance=250 (rollover credits → balance > included), elapsed 50%, prorated=50
    // used (100 − 250 = −150) → excess = max(0, −150 − 50) = 0
    $result = BillingPolicy::computeUsageOverageForPlanChange(100, 250, $periodStart, $periodEnd);

    expect($result['excess'])->toBe(0);
});

it('computes full quota as excess at period start when balance is zero', function (): void {
    $periodStart = now();
    $periodEnd = now()->addDays(30);

    // elapsed = 0, prorated = 0; but used = 1000 − 0 = 1000 → excess = 1000.
    // (Realistic only if a brand-new period was somehow already fully consumed —
    // sanity-check that the formula does not silently zero out used quota.)
    $result = BillingPolicy::computeUsageOverageForPlanChange(1000, 0, $periodStart, $periodEnd);

    expect($result['prorated_old_quota'])->toBe(0);
    expect($result['excess'])->toBe(1000);
});

it('computes zero excess at period start when nothing has been consumed', function (): void {
    $periodStart = now();
    $periodEnd = now()->addDays(30);

    // Brand-new period, balance still equals included → used = 0 → excess = 0.
    $result = BillingPolicy::computeUsageOverageForPlanChange(1000, 1000, $periodStart, $periodEnd);

    expect($result['excess'])->toBe(0);
    expect($result['prorated_old_quota'])->toBe(0);
});

it('computes excess when usage burned far more than the early-period prorated entitlement', function (): void {
    // Day 2 of a 30-day period: prorated entitlement = ~7 of a 100-quota,
    // but the user already burned 70 → excess = 63 must be charged on plan-change.
    $periodStart = now()->subDays(2);
    $periodEnd = now()->addDays(28);

    $result = BillingPolicy::computeUsageOverageForPlanChange(100, 30, $periodStart, $periodEnd);

    expect($result['prorated_old_quota'])->toBe(7);
    // used = 100 − 30 = 70; excess = 70 − 7 = 63
    expect($result['excess'])->toBe(63);
});

// ── remainingBillingQuota capping ───────────────────────────────────────────

it('does not cap remainingBillingQuota for extra credits when rollover is disabled', function (): void {
    config()->set('mollie-billing.usage_rollover', false);

    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);
    // Deposit more than included (e.g. via one-time order purchase)
    $service->credit($billable, 'Tokens', 150);

    // Extra credits from purchases are always available, regardless of rollover
    expect($billable->remainingBillingQuota('Tokens'))->toBe(150);
});

it('does not cap remainingBillingQuota when rollover is enabled', function (): void {
    config()->set('mollie-billing.usage_rollover', true);

    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 250);

    // With rollover, remaining should show actual balance
    expect($billable->remainingBillingQuota('Tokens'))->toBe(250);
});

it('usedBillingQuota stays consistent with remainingBillingQuota', function (): void {
    config()->set('mollie-billing.usage_rollover', false);

    $billable = makeTestBillable();
    $service = app(WalletUsageService::class);
    $service->credit($billable, 'Tokens', 100);
    $service->debit($billable, 'Tokens', 30);

    $used = $billable->usedBillingQuota('Tokens');
    $remaining = $billable->remainingBillingQuota('Tokens');

    expect($used)->toBe(30);
    expect($remaining)->toBe(70);
    expect($used + $remaining)->toBe(100); // used + remaining = included
});

// ── usageRollover config ────────────────────────────────────────────────────

it('reads rollover from global config', function (): void {
    config()->set('mollie-billing.usage_rollover', true);
    $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);

    expect($catalog->usageRollover('pro'))->toBeTrue();

    config()->set('mollie-billing.usage_rollover', false);
    expect($catalog->usageRollover('pro'))->toBeFalse();
});

it('reads rollover from plan-level config overriding global', function (): void {
    config()->set('mollie-billing.usage_rollover', false);
    config()->set('mollie-billing-plans.plans.pro.usage_rollover', true);

    $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);

    expect($catalog->usageRollover('pro'))->toBeTrue();
});
