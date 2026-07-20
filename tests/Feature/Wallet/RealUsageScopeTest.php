<?php

declare(strict_types=1);

use Bavix\Wallet\Models\Transaction;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
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
                'included_usages' => ['emails' => 100],
            ],
        ],
    ]);
});

function makeUsageBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Acme', 'email' => 'usage@b.test']);
    $b->forceFill([
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $b;
}

it('classifies bookkeeping reasons as non-usage', function (): void {
    expect(WalletUsageService::isUsageReason('usage'))->toBeTrue();
    expect(WalletUsageService::isUsageReason(null))->toBeTrue();
    expect(WalletUsageService::isUsageReason('api_call'))->toBeTrue();

    expect(WalletUsageService::isUsageReason('period_reset'))->toBeFalse();
    expect(WalletUsageService::isUsageReason('plan_change_reset'))->toBeFalse();
    expect(WalletUsageService::isUsageReason('subscription_renewal'))->toBeFalse();
    expect(WalletUsageService::isUsageReason('coupon_credit'))->toBeFalse();
    expect(WalletUsageService::isUsageReason('one_time_order:starter-pack'))->toBeFalse();
});

it('excludes purchases and period resets from the usage statistics query', function (): void {
    $billable = makeUsageBillable();
    $service = app(WalletUsageService::class);

    // Plan quota + a purchased credit pack.
    $service->credit($billable, 'emails', 100, 'subscription_activation');
    $service->credit($billable, 'emails', 500, 'one_time_order:starter-pack');

    // Real consumption.
    $service->debit($billable, 'emails', 30);
    $service->debit($billable, 'emails', 12, 'api_call');

    // Renewal with a period reset (withdraw + deposit, both bookkeeping).
    $service->resetAndCredit($billable, 'emails', 100, 'subscription_renewal');

    $walletIds = $billable->refresh()->wallets->where('slug', '!=', 'default')->pluck('id');

    $usage = WalletUsageService::scopeRealUsage(
        Transaction::query()->whereIn('wallet_id', $walletIds)
    )->where('type', 'withdraw');

    expect((int) (clone $usage)->count())->toBe(2);
    expect(abs((int) (clone $usage)->sum('amount')))->toBe(42);
});
