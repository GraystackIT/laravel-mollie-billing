<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\TrialQuota;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/**
 * A plan change during a running trial rebalances against what the trial was granted.
 *
 * The adjuster's baseline is the old plan's period quota, which a trial never received:
 * a wallet credited 10 of a 20-unit quota looked like 10 units consumed, and with the
 * trial barely elapsed the prorated entitlement is near zero, so all 10 came out as
 * excess — deducted from the new plan's quota and, past that, charged as overage for
 * consumption that never happened.
 */
beforeEach(function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_overage_trial', 'status' => 'open']);

    Carbon\Carbon::setTestNow(Carbon\Carbon::create(2026, 4, 20, 12, 0, 0, 'UTC'));

    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 20],
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'trial_days' => 14,
                'included_usages' => ['Tokens' => 10],
                'usage_overage_prices' => ['Tokens' => 12],
            ],
        ],
    ]);
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

function trialRebalanceBillable(int $walletBalance = 10): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial Rebalance', 'email' => 'rebalance@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_country' => 'AT',
        'mollie_mandate_id' => 'mdt_t',
        'mollie_customer_id' => 'cst_t',
        'allows_billing_overage' => true,
        // Two days into a 14-day trial. The period anchor is the mandate capture, so
        // barely any of the nominal 30-day period has elapsed.
        'trial_ends_at' => BillingTime::nowUtc()->addDays(12),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(2),
        'subscription_meta' => [
            'trial_quota' => TrialQuota::marker('pro', 'monthly', 14, ['Tokens' => 10]),
        ],
    ])->save();

    app(WalletUsageService::class)->credit($billable, 'Tokens', $walletBalance, 'subscription_trial_start');

    return $billable->refresh();
}

/**
 * Switch the billable onto Starter and rebalance, in the order every caller uses: the
 * new plan is saved first and the old boundaries are handed to the adjuster explicitly,
 * because they can no longer be read off the model.
 */
function rebalanceOntoStarter(TestBillable $billable): void
{
    $oldPeriodStart = $billable->getBillingPeriodStartsAt();
    $oldPeriodEnd = $billable->nextBillingDate();

    $billable->forceFill(['subscription_plan_code' => 'starter'])->save();

    app(WalletPlanChangeAdjuster::class)->adjust(
        $billable,
        'pro',
        'monthly',
        'starter',
        'monthly',
        $oldPeriodStart,
        $oldPeriodEnd,
    );

    $billable->refresh();
}

it('does not read an untouched trial wallet as fully consumed', function (): void {
    $billable = trialRebalanceBillable();

    rebalanceOntoStarter($billable);

    // Nothing was consumed, so nothing is owed: the trial continues on Starter with its
    // prorated share of that plan — ceil(10 × 14/30) = 5.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(5);
    expect($billable->getBillingSubscriptionMeta()['usage_overage'] ?? null)->toBeNull();
});

it('re-grants the trial share of the new plan and records it', function (): void {
    $billable = trialRebalanceBillable();

    rebalanceOntoStarter($billable);

    $marker = $billable->getBillingSubscriptionMeta()['trial_quota'] ?? null;
    expect($marker['plan_code'])->toBe('starter');
    expect($marker['usages'])->toBe(['Tokens' => 5]);

    // The meter now measures against the new grant — and reads no usage, which is what
    // happened.
    expect($billable->grantedBillingQuota('Tokens'))->toBe(5);
    expect($billable->usedBillingQuota('Tokens'))->toBe(0);
});

it('still settles what a trial really consumed', function (): void {
    // 8 of the 10 granted units used, then a downgrade to a plan whose trial share is 5.
    $billable = trialRebalanceBillable(walletBalance: 2);

    rebalanceOntoStarter($billable);

    // 8 consumed against a prorated entitlement of ~0 two days in leaves excess to settle
    // against the new 5-unit share, so the wallet lands below it rather than at it.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBeLessThan(5);
});

it('leaves the rebalance of a paying subscription alone', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Paying', 'email' => 'paying-rebalance@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_country' => 'AT',
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(2),
    ])->save();

    app(WalletUsageService::class)->credit($billable, 'Tokens', 20, 'subscription_activation');

    rebalanceOntoStarter($billable);

    // Full quota in, full quota of the new plan out — and no trial marker invented.
    expect((int) $billable->getWallet('Tokens')->refresh()->balanceInt)->toBe(10);
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('trial_quota');
});
