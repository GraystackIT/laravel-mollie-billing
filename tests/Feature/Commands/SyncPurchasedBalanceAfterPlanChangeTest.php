<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * `billing:sync-purchased-balance` resurrects consumed purchased credits
 * after a plan change.
 *
 * The replay clamps the running purchased total only at renewal markers. A plan change
 * is the other place the package clamps `purchased_balance`
 * (`WalletPlanChangeAdjuster` writes `min(purchasedRemaining, finalBalance)`), and its
 * transactions (`plan_change_reset` / `plan_change_credit`) are not clamp points. So a
 * pack that was almost entirely consumed before an upgrade is re-attributed out of the
 * new plan's quota, and every later non-rollover reset re-credits it.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    foreach (['pro' => 100, 'max' => 1000] as $code => $quota) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $quota,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => $quota * 10,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => $quota],
                    'usage_overage_prices' => ['Tokens' => 5],
                ],
            ],
        ]);
    }
});

it('does not resurrect purchased credits that were consumed before a plan change', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Sync Co', 'email' => 'sync-plan-change@x.test', 'billing_country' => 'AT']);

    $billable->forceFill([
        'mollie_customer_id' => 'cst_sync',
        'mollie_mandate_id' => 'mdt_sync',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(15),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();
    $billable->refresh();

    $wallets = app(WalletUsageService::class);

    // Plan quota 100, then a 500-unit pack: balance 600, of which 500 purchased.
    $wallets->credit($billable, 'Tokens', 100, 'subscription_activation');
    $wallets->credit($billable, 'Tokens', 500, 'one_time_order:pack-500');
    WalletUsageService::addPurchasedBalance($billable->getWallet('Tokens'), 500);

    // 590 used: the plan quota and 490 of the pack. 10 purchased units are left.
    $wallets->debit($billable, 'Tokens', 590);
    expect($billable->refresh()->purchasedBillingCredits('Tokens'))->toBe(10);

    // Upgrade to Max (1 000 / month) halfway through the period.
    $billable->forceFill(['subscription_plan_code' => 'max'])->save();

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable->refresh(),
        'pro', 'monthly', 'max', 'monthly',
        now()->subDays(15),
        now()->addDays(15),
        deferOverageCharge: true,
    );

    $wallet = $billable->refresh()->getWallet('Tokens')->refresh();
    $balanceAfterChange = (int) $wallet->balanceInt;

    // The adjuster itself has it right: only the 10 surviving units are "purchased".
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(10);

    $this->artisan('billing:sync-purchased-balance', ['--force' => true])->assertSuccessful();

    $wallet->refresh();

    // The documented "safe to re-run" backfill must agree with the live bookkeeping.
    // Current code writes min(500, balance) = 500: 490 units of plan quota relabelled
    // as purchased, which then survive every period reset.
    expect(WalletUsageService::getPurchasedBalance($wallet))->toBe(
        10,
        "Backfill wrote a purchased balance that the wallet never held (wallet balance {$balanceAfterChange}).",
    );

    // …and what that costs at the next renewal: quota 1 000 + 10 purchased = 1 010.
    $wallets->resetAndCredit($billable->refresh(), 'Tokens', 1000, 'subscription_renewal');

    expect((int) $billable->refresh()->getWallet('Tokens')->refresh()->balanceInt)->toBe(1010);
});
