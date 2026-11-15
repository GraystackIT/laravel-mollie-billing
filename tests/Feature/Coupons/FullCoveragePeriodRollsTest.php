<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

/*
 * A fully covered (100 % recurring coupon) Mollie subscription receives no renewal
 * webhook for the coupon's lifetime — Mollie's start date is deferred past it. Nothing
 * else advances the local billing period, so after the first interval the subscription
 * is "stuck between periods" for as long as the coupon runs.
 *
 * Both tests were written against the unfixed code and seen to fail there. The nightly
 * pass now turns the period over itself — PrepareUsageOverageJob, case A0.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => 500,
                'included_usages' => ['tokens' => 100],
                'usage_overage_prices' => ['tokens' => 10],
            ],
        ],
    ]);
});

function coveredBillableWithQuota(): TestBillable
{
    $service = app(CouponService::class);
    $coupon = $service->create([
        'code' => 'FREE6M',
        'name' => 'Plan free for 6 months',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 6,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Granted', 'email' => 'g@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test', 'seat_count' => 1],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    $service->applyRecurringMarker($coupon, $billable->fresh(), 1000, 'monthly');
    app(WalletUsageService::class)->credit($billable->fresh(), 'tokens', 100, 'subscription_activation');

    return $billable->fresh();
}

it('lets a fully covered subscriber buy a seat in the second month of the coupon', function (): void {
    $billable = coveredBillableWithQuota();

    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => (object) [
        'id' => 'tr_test',
        'status' => 'open',
        'getCheckoutUrl' => null,
    ]);

    // Day 45, well into the second period. The nightly pass is what turns the period
    // over for a subscription Mollie is not charging — and it catches up on a period
    // that slid before it learned to (the first night after deploying the fix).
    Carbon::setTestNow(BillingTime::nowUtc()->addDays(45));
    app()->call([new PrepareUsageOverageJob, 'handle']);

    expect($billable->fresh()->nextBillingDate()->isFuture())->toBeTrue();

    $refused = null;

    try {
        app(UpdateSubscription::class)->update($billable->fresh(), ['seats' => 2]);
    } catch (InvalidSubscriptionStateException $e) {
        $refused = $e->getMessage();
    } catch (\Throwable) {
        // Anything past the guard (the faked Mollie answer is minimal) is not what
        // this test is about.
    }

    Carbon::setTestNow();

    expect($refused)->toBeNull();
});

it('recharges the included quota each period while the coupon covers the charge', function (): void {
    $billable = coveredBillableWithQuota();

    // The whole first period's allowance is used up.
    $billable->recordBillingUsage('tokens', 100);
    expect((int) $billable->fresh()->getWallet('tokens')->balanceInt)->toBe(0);

    Mollie::shouldReceive('send')->never();

    // The nightly pass runs every day for 45 days. A paying subscriber is recharged by
    // the renewal webhook on day 30; this one gets no webhook.
    $start = BillingTime::nowUtc();
    for ($day = 1; $day <= 45; $day++) {
        Carbon::setTestNow($start->copy()->addDays($day));
        app()->call([new PrepareUsageOverageJob, 'handle']);
    }

    $fresh = $billable->fresh();
    $nextBilling = $fresh->nextBillingDate();
    $now = BillingTime::nowUtc();
    Carbon::setTestNow();

    // Read from the ledger, not the balance column: bavix writes the column on commit,
    // which RefreshDatabase never does, and caches balances for 24 h.
    $renewals = \Illuminate\Support\Facades\DB::table('transactions')
        ->where('type', 'deposit')
        ->where('meta', 'like', '%subscription_renewal%')
        ->get();

    // Exactly one boundary (day 30) lies inside the 45 days: one refill, not one per night.
    expect($renewals)->toHaveCount(1, 'second period started on day 30 — its 100 included tokens were never credited');
    expect((int) $renewals->first()->amount)->toBe(100);
    expect($nextBilling->greaterThan($now))->toBeTrue();
});
