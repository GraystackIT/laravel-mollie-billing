<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * The proration baseline is the OLD billing period. Every caller saves the new
 * plan/interval — and sometimes a new period start — before calling adjust(),
 * so anything re-derived from live model state is wrong by construction.
 */
beforeEach(function (): void {
    // Pinned so that "15 days ago" and "5 days ago" both start inside a 30-day
    // month: the derived old-period end uses addMonth() (same as
    // HasBilling::nextBillingDate()), which overflows for month-end starts.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 4, 20, 12, 0, 0, 'UTC'));

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_overage_1', 'status' => 'open']);

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
                'included_usages' => ['Tokens' => 1000],
                'usage_overage_prices' => ['Tokens' => 2],
            ],
            'yearly' => [
                'base_price_net' => 10000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 12000],
                'usage_overage_prices' => ['Tokens' => 2],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
        ],
    ]);
});

afterEach(function (): void {
    \Carbon\Carbon::setTestNow();
});

function adjusterBillable(int $balance, string $interval = 'monthly', ?string $periodStart = null): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'a@b.test']);
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => $interval === 'yearly' ? SubscriptionInterval::Yearly : SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $periodStart ?? now()->subDays(15),
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_test',
        'mollie_customer_id' => 'cst_test',
        'allows_billing_overage' => true,
    ])->save();

    $wallet = $b->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    if ($balance > 0) {
        $wallet->deposit($balance);
    }

    return $b->refresh();
}

it('uses the explicitly passed old period rather than live model state', function (): void {
    // 1000 included, 500 consumed (balance 500), 15 of 30 days elapsed →
    // prorated entitlement 500 → no excess. The customer used exactly their share.
    $billable = adjusterBillable(500);
    $periodStart = now()->subDays(15);
    $periodEnd = now()->addDays(15);

    // Simulate the caller: the new interval is already saved when adjust() runs.
    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Yearly])->save();

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'pro',
        'monthly',
        'pro',
        'yearly',
        $periodStart,
        $periodEnd,
    );

    // Target = newIncluded (12000) − excess (0)
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(12000);
});

it('derives the old period end from the old interval, not the saved new one', function (): void {
    // Without explicit boundaries the old code called nextBillingDate(), which
    // already used the NEW (yearly) interval: 15/365 elapsed → entitlement 41 →
    // 459 units of phantom "excess" for a customer who stayed within quota.
    $billable = adjusterBillable(500);

    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Yearly])->save();

    app(WalletPlanChangeAdjuster::class)->adjust($billable->refresh(), 'pro', 'monthly', 'pro', 'yearly');

    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(12000);
});

it('does not put the derived period end in the past on a yearly to monthly change', function (): void {
    // Period started 100 days ago on a yearly plan. Deriving the end from the new
    // monthly interval would land ~70 days in the past → factor 0 → elapsed 1.0.
    $billable = adjusterBillable(11000, 'yearly', (string) now()->subDays(100));

    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Monthly])->save();

    app(WalletPlanChangeAdjuster::class)->adjust($billable->refresh(), 'pro', 'yearly', 'pro', 'monthly');

    // 12000 included yearly, 1000 consumed, ~100/365 elapsed → entitlement 3288,
    // used 1000 → no excess → target = new monthly quota 1000.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(1000);
});

it('bills real excess when the caller passes the old period', function (): void {
    // 1000 included, 900 consumed (balance 100) after 15 of 30 days →
    // entitlement 500, used 900 → excess 400.
    $billable = adjusterBillable(100);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable,
        'pro',
        'monthly',
        'free',
        'monthly',
        now()->subDays(15),
        now()->addDays(15),
    );

    // Target = free quota 10 − excess 400 → negative → clamped to 0 and charged.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(0);

    $pending = $billable->refresh()->getBillingSubscriptionMeta()['usage_overage'] ?? null;
    expect($pending)->not->toBeNull();
    expect($pending['line_items'][0]['quantity'])->toBe(390);
});

it('does not invent excess when a caller reset the period start before calling', function (): void {
    // The free-downgrade path writes subscription_period_starts_at = now() before
    // adjust() runs. Elapsed would be 0, the prorated entitlement 0 and the whole
    // period's legitimate in-quota usage would become excess — 400 units billed
    // out of thin air. The baseline is unrecoverable here, so nothing is charged.
    $billable = adjusterBillable(600, 'monthly', (string) now());

    app(WalletPlanChangeAdjuster::class)->adjust($billable, 'pro', 'monthly', 'free', 'monthly');

    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(10);
    expect($billable->refresh()->getBillingSubscriptionMeta()['usage_overage'] ?? null)->toBeNull();
});

it('honours an explicitly passed zero-elapsed period', function (): void {
    // When the caller states the boundaries, they are the truth even if the
    // period has not elapsed — no silent guard second-guessing the caller.
    $billable = adjusterBillable(600);

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable,
        'pro',
        'monthly',
        'free',
        'monthly',
        now(),
        now()->addDays(30),
    );

    // Entitlement 0, used 400 → excess 400 → target 10 − 400 → clamped to 0.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(0);
});

it('keeps the documented old-period semantics for an unchanged period start', function (): void {
    // Pins the one caller configuration that was already correct: monthly → monthly,
    // period start untouched. 100 included, 40 used, 5 of 30 days elapsed.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
        ],
    ]);

    $billable = adjusterBillable(60, 'monthly', (string) now()->subDays(5));

    app(WalletPlanChangeAdjuster::class)->adjust($billable, 'pro', 'monthly', 'basic', 'monthly');

    // entitlement round(100 * 5/30) = 17, used 40 → excess 23 → target 50 − 23 = 27
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(27);
});

it('bills the debt of a pay-per-use type on a plan change instead of forgiving it', function (): void {
    // A usage type may be priced without any included quota — every unit is billed, the
    // wallet only ever goes negative. The excess calculation was gated on the old plan
    // including some of it, so for exactly this shape it computed nothing: the rebalance
    // reset the wallet to the new plan's quota and the whole consumed, unbilled debt
    // simply disappeared. A plan change was a way to use the metered feature for free.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.usage_overage_prices.SMS', 5);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.usage_overage_prices.SMS', 5);

    $billable = adjusterBillable(500);
    $billable->createWallet(['name' => 'SMS', 'slug' => 'SMS'])->forceWithdraw(120);

    $lines = app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'pro',
        'monthly',
        'pro',
        'yearly',
        now()->subDays(15),
        now()->addDays(15),
        deferOverageCharge: true,
    );

    $sms = collect($lines)->firstWhere('code', 'SMS');

    expect($sms)->not->toBeNull()
        ->and((int) $sms['quantity'])->toBe(120)
        ->and((int) $sms['unit_price_net'])->toBe(5)
        ->and((int) $sms['total_net'])->toBe(600);
});
