<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing-plans.plans.grant', [
        'name' => 'Granted',
        'tier' => 1,
        'included_seats' => 1,
        'intervals' => [
            'monthly' => [
                'base_price_net' => 0,
                'included_usages' => ['tokens' => 500],
                'usage_overage_prices' => ['tokens' => false],
            ],
        ],
    ]);
});

it('does not hand a lapsed grant one more period of wallet units before expiring it', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Lapsed grant',
        'email' => uniqid('lg-').'@x.test',
        'billing_country' => 'AT',
    ]);

    // A coupon access grant: local subscription, status stays Active, and
    // `subscription_ends_at` is what ends it. The period boundary falls tomorrow, so the
    // recharge pass matches it — while the expiry pass matches it too.
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'grant',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth()->addDay(),
        'subscription_ends_at' => BillingTime::nowUtc()->subHour(),
    ])->save();

    $periodStart = $b->fresh()->subscription_period_starts_at;

    PrepareUsageOverageJob::dispatchSync();

    $b = $b->fresh();

    expect($b->subscription_status)->toBe(SubscriptionStatus::Expired);

    // The recharge used to run first, so the very invocation that ended the grant had
    // already credited a full extra period of units to it.
    expect((int) ($b->getWallet('tokens')?->balanceInt ?? 0))->toBe(0);
    expect($b->subscription_period_starts_at?->toIso8601String())
        ->toBe($periodStart?->toIso8601String());
});

it('still recharges a local subscription that has not lapsed', function (): void {
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Live grant',
        'email' => uniqid('lv-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'grant',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonth()->addDay(),
    ])->save();

    PrepareUsageOverageJob::dispatchSync();

    $b = $b->fresh();

    expect($b->subscription_status)->toBe(SubscriptionStatus::Active);
    expect((int) ($b->getWallet('tokens')?->balanceInt ?? 0))->toBe(500);
});
