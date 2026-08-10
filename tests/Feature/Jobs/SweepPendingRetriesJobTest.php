<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

function sweepBillable(array $meta): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Sweep '.uniqid(),
        'email' => uniqid('sw-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $meta,
    ])->save();

    return $b->refresh();
}

it('dispatches a patch retry for an intent-shaped pending_subscription_patch marker', function (): void {
    Bus::fake([RetrySubscriptionPatchJob::class]);

    $b = sweepBillable([
        'mollie_subscription_id' => 'sub_sweep',
        'pending_subscription_patch' => [
            'intent' => [
                'billable_type' => TestBillable::class,
                'billable_id' => 1,
                'current_plan' => 'starter', 'new_plan' => 'pro',
                'current_interval' => 'monthly', 'new_interval' => 'monthly',
                'current_seats' => 1, 'new_seats' => 1,
                'current_addons' => [], 'new_addons' => [],
            ],
            'first_attempt_at' => BillingTime::nowUtc()->subMinutes(5)->toIso8601String(),
            'last_error' => 'Mollie 503',
        ],
    ]);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertDispatched(
        RetrySubscriptionPatchJob::class,
        fn (RetrySubscriptionPatchJob $job) => $job->intentData['new_plan'] === 'pro'
            && $job->intentData['billable_id'] === 1,
    );

    expect($b->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch'])->not->toBeNull();
});

it('injects billable identity into the flat coupon-expiry patch marker', function (): void {
    Bus::fake([RetrySubscriptionPatchJob::class]);

    $b = sweepBillable([
        'mollie_subscription_id' => 'sub_sweep2',
        'pending_subscription_patch' => [
            'reason' => 'recurring_coupon_expiry_reset',
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addons' => [],
            'extra_seats' => 0,
            'first_attempt_at' => BillingTime::nowUtc()->subMinutes(5)->toIso8601String(),
        ],
    ]);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertDispatched(
        RetrySubscriptionPatchJob::class,
        fn (RetrySubscriptionPatchJob $job) => $job->intentData['reason'] === 'recurring_coupon_expiry_reset'
            && $job->intentData['billable_type'] === $b->getMorphClass()
            && $job->intentData['billable_id'] === $b->getKey(),
    );
});

it('dispatches one refund retry per pending_refund_retries entry', function (): void {
    Bus::fake([RetryRefundLineJob::class]);

    $b = sweepBillable([
        'pending_refund_retries' => [
            [
                'line' => ['kind' => 'addon', 'code' => 'a', 'parent_invoice_id' => 1, 'parent_line_item_index' => 0, 'amount_net' => -500],
                'error' => 'Mollie 422',
                'first_attempt_at' => BillingTime::nowUtc()->subHour()->toIso8601String(),
            ],
            [
                'line' => ['kind' => 'seats', 'code' => 'b', 'parent_invoice_id' => 1, 'parent_line_item_index' => 1, 'amount_net' => -200],
                'error' => 'Mollie 422',
            ],
        ],
    ]);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertDispatchedTimes(RetryRefundLineJob::class, 2);
    Bus::assertDispatched(
        RetryRefundLineJob::class,
        fn (RetryRefundLineJob $job) => $job->billableId === $b->getKey()
            && $job->lineData['parent_invoice_id'] === 1,
    );
});

it('falls back to now for an entry without first_attempt_at instead of dead-lettering it', function (): void {
    Bus::fake([RetryRefundLineJob::class]);

    sweepBillable([
        'pending_refund_retries' => [
            ['line' => ['parent_invoice_id' => 7, 'parent_line_item_index' => 0, 'amount_net' => -100]],
        ],
    ]);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertDispatched(
        RetryRefundLineJob::class,
        fn (RetryRefundLineJob $job) => \Carbon\Carbon::parse($job->firstAttemptAt)->diffInMinutes(BillingTime::nowUtc()) < 2,
    );
});

it('dispatches nothing when no billable carries a marker', function (): void {
    Bus::fake([RetryRefundLineJob::class, RetrySubscriptionPatchJob::class]);

    sweepBillable(['mollie_subscription_id' => 'sub_clean']);

    (new SweepPendingRetriesJob)->handle();

    Bus::assertNotDispatched(RetryRefundLineJob::class);
    Bus::assertNotDispatched(RetrySubscriptionPatchJob::class);
});
