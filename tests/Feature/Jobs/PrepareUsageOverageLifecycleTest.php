<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionExpired;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.past_due_max_days', 30);

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))
        ->getProperty('notifyBillingAdminsCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function overageLifecycleBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Lifecycle '.uniqid(),
        'email' => uniqid('lc-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ], $overrides))->save();

    return $b->refresh();
}

it('leaves an auto-cancelled billable observable as Cancelled in the same run', function (): void {
    Notification::fake();

    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'past_due_since' => BillingTime::nowUtc()->subDays(45)->toIso8601String(),
        ],
    ]);

    PrepareUsageOverageJob::dispatchSync();

    // The expiry pass runs before the auto-cancel pass, so a billable cancelled by
    // this invocation can only be expired by the next one.
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($b->fresh()->subscription_ends_at)->not->toBeNull();

    // Next day's run: ends_at is now in the past, so it expires.
    $this->travel(1)->days();
    PrepareUsageOverageJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
});

it('dispatches SubscriptionCancelled and notifies on the past-due auto-cancel', function (): void {
    Notification::fake();
    Event::fake([SubscriptionCancelled::class]);

    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_meta' => [
            'past_due_since' => BillingTime::nowUtc()->subDays(45)->toIso8601String(),
        ],
    ]);

    PrepareUsageOverageJob::dispatchSync();

    Event::assertDispatched(
        SubscriptionCancelled::class,
        fn (SubscriptionCancelled $e) => $e->billable->getKey() === $b->getKey() && $e->immediately === true,
    );
    Notification::assertSentTo($b, SubscriptionCancelledNotification::class);
});

it('dispatches SubscriptionExpired when a cancelled subscription reaches its end date', function (): void {
    Event::fake([SubscriptionExpired::class]);

    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->subMinute(),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
    Event::assertDispatched(
        SubscriptionExpired::class,
        fn (SubscriptionExpired $e) => $e->billable->getKey() === $b->getKey(),
    );
});

it('expires an Active billable whose access grant has lapsed', function (): void {
    Event::fake([SubscriptionExpired::class]);

    // A coupon access grant sets subscription_ends_at and leaves the status Active.
    // Pass 3 only looked at Cancelled, so the row kept its plan code — which is all
    // FeatureAccess consults — and kept being picked up by Pass 1's wallet recharge.
    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
    Event::assertDispatched(SubscriptionExpired::class);
});

it('expires a Trial billable whose grant window has lapsed', function (): void {
    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
});

it('leaves an Active billable with a future end date alone', function (): void {
    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->addMonth(),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('does not re-charge in pass 2 when pass 1 already created the payment', function (): void {
    Bus::fake([RetryUsageOverageChargeJob::class]);

    $b = overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_lc',
        'mollie_mandate_id' => 'mdt_lc',
        // A charge that reached Mollie: the snapshot carries a payment id, and the
        // marker is the same `pending` the failure path uses.
        'subscription_meta' => [
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            'usage_overage' => [
                'payment_id' => 'tr_overage_inflight',
                'line_items' => [['type' => 'tokens', 'quantity' => 100, 'unit_price_net' => 1, 'total_net' => 100]],
            ],
        ],
    ]);

    PrepareUsageOverageJob::dispatchSync();

    Bus::assertNotDispatched(RetryUsageOverageChargeJob::class);
    expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage']['payment_id'] ?? null)
        ->toBe('tr_overage_inflight');
});

it('retries in pass 2 when the charge never reached Mollie', function (): void {
    Bus::fake([RetryUsageOverageChargeJob::class]);

    overageLifecycleBillable([
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_lc2',
        'mollie_mandate_id' => 'mdt_lc2',
        // Snapshot without a payment id — the Mollie call itself failed.
        'subscription_meta' => [
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            'usage_overage' => [
                'payment_id' => null,
                'line_items' => [['type' => 'tokens', 'quantity' => 100, 'unit_price_net' => 1, 'total_net' => 100]],
            ],
        ],
    ]);

    PrepareUsageOverageJob::dispatchSync();

    Bus::assertDispatched(RetryUsageOverageChargeJob::class);
});
