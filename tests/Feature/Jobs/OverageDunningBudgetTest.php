<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\UsageOverageChargeResult;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
});

afterEach(function (): void {
    foreach (['notifyBillingAdminsCallback', 'notifyAdminCallback'] as $property) {
        $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
});

function dunningBillable(string $paymentId, array $extraMeta = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Dunning '.uniqid(),
        'email' => uniqid('du-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_customer_id' => 'cst_dun',
        'mollie_mandate_id' => 'mdt_dun',
        'subscription_meta' => array_merge([
            'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            'usage_overage' => [
                'payment_id' => $paymentId,
                'created_at' => '2026-08-01T00:00:00+00:00',
                'line_items' => [['type' => 'tokens', 'quantity' => 10, 'unit_price_net' => 5, 'total_net' => 50]],
            ],
        ], $extraMeta),
    ])->save();

    return $b->refresh();
}

/**
 * Charge-service double. `retry()` either throws (the Mollie call itself failed — no
 * money moved) or reports a fresh payment, which is what the real service does after
 * persistPendingCharge() wrote the new id.
 */
function bindChargeService(?\Closure $onRetry = null): ChargeUsageOverageDirectly
{
    $service = new class($onRetry) extends ChargeUsageOverageDirectly
    {
        public function __construct(private readonly ?\Closure $onRetry) {}

        public function retry(\GraystackIT\MollieBilling\Contracts\Billable $billable): UsageOverageChargeResult
        {
            if ($this->onRetry !== null) {
                return ($this->onRetry)($billable);
            }

            throw new RuntimeException('Mollie 503 Service Unavailable');
        }
    };

    app()->instance(ChargeUsageOverageDirectly::class, $service);

    return $service;
}

it('does not spend the dunning budget on repeated API errors for the same payment', function (): void {
    Notification::fake();

    $b = dunningBillable('tr_unsettled');
    $service = bindChargeService();

    // Three queue attempts, all of them failing before Mollie accepted anything. The
    // customer's payment method was touched exactly once — by tr_unsettled.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => (new RetryUsageOverageChargeJob(TestBillable::class, $b->getKey()))->handle($service))
            ->toThrow(RuntimeException::class);
    }

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($meta['usage_overage_attempts'])->toBe(1);
    expect($meta['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($meta)->not->toHaveKey('past_due_since');

    Notification::assertNothingSent();
});

it('gives the cycle up once three distinct payments have failed, tells the customer and leaves the debt standing', function (): void {
    Notification::fake();

    $b = dunningBillable('tr_one');
    $service = bindChargeService(fn () => UsageOverageChargeResult::charged('tr_next', [], 50, 60));

    foreach (['tr_one', 'tr_two', 'tr_three'] as $index => $paymentId) {
        // Each webhook failure leaves a *different* unsettled payment on the snapshot,
        // which is what the budget is meant to count.
        $meta = $b->fresh()->getBillingSubscriptionMeta();
        $meta['usage_overage']['payment_id'] = $paymentId;
        $b->forceFill(['subscription_meta' => $meta])->save();

        (new RetryUsageOverageChargeJob(TestBillable::class, $b->getKey()))->handle($service);

        expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage_attempts'])->toBe($index + 1);
    }

    // Past due — for the debt: nothing new is booked until it is settled. Under its own
    // key, not on the clock of a failed renewal (asserted below), so the renewals that
    // go on to collect it are not cut short by `past_due_max_days`.
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
    expect($b->fresh()->getBillingSubscriptionMeta())->toHaveKey(ChargeUsageOverageDirectly::PAST_DUE_KEY);
    expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(RetryUsageOverageChargeJob::OVERAGE_FAILED);
    expect($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since');

    // The customer hears about it; operations do not — there is nothing for them to do
    // until the renewals have had their attempts as well.
    Notification::assertSentTo($b, OverageBillingFailedNotification::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, AdminOverageBillingFailedNotification::class);
});

it('starts a fresh budget for the next dunning cycle', function (): void {
    Notification::fake();

    // A spent budget from a cycle that has since been settled and replaced: the snapshot
    // carries a new `created_at`, so the counted ids of the old cycle must not count.
    $b = dunningBillable('tr_new_cycle', [
        'usage_overage_counted_payments' => [
            'cycle' => '2026-07-01T00:00:00+00:00',
            'payment_ids' => ['tr_a', 'tr_b', 'tr_c'],
        ],
    ]);

    $service = bindChargeService(fn () => UsageOverageChargeResult::charged('tr_next', [], 50, 60));

    (new RetryUsageOverageChargeJob(TestBillable::class, $b->getKey()))->handle($service);

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage_attempts'])->toBe(1);

    Notification::assertNothingSent();
});

it('tells the admins when the retries run out without ever reaching Mollie', function (): void {
    Notification::fake();

    // The snapshot never got a payment id, so nothing was charged and nothing was
    // dunned — but the negative wallet balance is still unbilled and somebody has to know.
    $b = dunningBillable('', ['usage_overage' => [
        'payment_id' => null,
        'created_at' => BillingTime::nowUtc()->toIso8601String(),
        'line_items' => [],
    ]]);

    (new RetryUsageOverageChargeJob(TestBillable::class, $b->getKey()))
        ->failed(new RuntimeException('Mollie 503 Service Unavailable'));

    Notification::assertSentTo(new AnonymousNotifiable, AdminOverageBillingFailedNotification::class);

    // Left `pending` on purpose: tomorrow's PrepareUsageOverageJob pass owns the retry.
    expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

it('does not resurrect a cancelled billable into the dunning ladder', function (): void {
    // The customer has left; the grace period is running out on its own. Forcing PastDue
    // here undoes the cancellation: `subscription_ends_at` no longer means what it did,
    // ResubscribeSubscription refuses ("not in a cancelled state") so the customer cannot
    // resume, and the auto-cancel pass later re-cancels — recomputing the grace boundary
    // and handing out access the customer had already given up. ChargebackHandler and
    // SubscriptionPaymentHandler both guard this write; this one did not.
    Notification::fake();

    $b = dunningBillable('tr_gone');
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(9),
    ])->save();

    $service = bindChargeService(fn () => UsageOverageChargeResult::charged('tr_next', [], 50, 60));

    foreach (['tr_gone', 'tr_gone_2', 'tr_gone_3'] as $paymentId) {
        $meta = $b->fresh()->getBillingSubscriptionMeta();
        $meta['usage_overage']['payment_id'] = $paymentId;
        $b->forceFill(['subscription_meta' => $meta])->save();

        (new RetryUsageOverageChargeJob(TestBillable::class, $b->getKey()))->handle($service);
    }

    // The debt is still recorded and the admins still hear about it — only the status
    // of a subscription that has already ended is left alone.
    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($b->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])
        ->toBe(RetryUsageOverageChargeJob::OVERAGE_FAILED);

    Notification::assertSentTo($b, OverageBillingFailedNotification::class);
});
