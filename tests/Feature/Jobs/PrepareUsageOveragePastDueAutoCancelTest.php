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
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function makeAutoCancelPastDueBillable(?string $sinceIso): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'PastDue Co',
        'email' => 'pd-'.uniqid().'@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_pd',
        'mollie_mandate_id' => 'mdt_pd',
    ]);

    $meta = ['payment_failure' => ['reason' => 'insufficient_funds']];
    if ($sinceIso !== null) {
        $meta['past_due_since'] = $sinceIso;
    }

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $meta,
    ])->save();

    return $billable->refresh();
}

it('auto-cancels past_due billables older than past_due_max_days', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $stale = makeAutoCancelPastDueBillable(BillingTime::nowUtc()->subDays(35)->toIso8601String());

    PrepareUsageOverageJob::dispatchSync();

    $stale->refresh();
    expect($stale->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
    expect($stale->getBillingSubscriptionEndsAt())->not->toBeNull();
});

it('does not auto-cancel past_due billables within the grace window', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $recent = makeAutoCancelPastDueBillable(BillingTime::nowUtc()->subDays(5)->toIso8601String());

    PrepareUsageOverageJob::dispatchSync();

    $recent->refresh();
    expect($recent->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
    expect($recent->getBillingSubscriptionEndsAt())->toBeNull();
});

it('skips auto-cancel when past_due_max_days is 0 (disabled)', function (): void {
    config()->set('mollie-billing.past_due_max_days', 0);

    $stale = makeAutoCancelPastDueBillable(BillingTime::nowUtc()->subDays(365)->toIso8601String());

    PrepareUsageOverageJob::dispatchSync();

    $stale->refresh();
    expect($stale->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
});

it('skips auto-cancel when past_due_since marker is missing', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $legacy = makeAutoCancelPastDueBillable(null);
    $legacy->forceFill([
        'created_at' => now()->subDays(365),
        'updated_at' => now()->subDays(365),
    ])->save();

    PrepareUsageOverageJob::dispatchSync();

    $legacy->refresh();
    expect($legacy->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);
});

it('transitions cancelled past_due billable to expired on next Pass 3b run', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $stale = makeAutoCancelPastDueBillable(BillingTime::nowUtc()->subDays(35)->toIso8601String());

    PrepareUsageOverageJob::dispatchSync();

    $stale->refresh();
    expect($stale->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);

    $stale->forceFill([
        'subscription_ends_at' => BillingTime::nowUtc()->subSecond(),
    ])->save();

    PrepareUsageOverageJob::dispatchSync();

    $stale->refresh();
    expect($stale->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Expired);
    expect($stale->getBillingSubscriptionSource())->toBe(SubscriptionSource::None->value);
});
