<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/*
 * The retry jobs on the `sync` queue driver.
 *
 * On `sync` a dispatched job runs at once and a job that throws has failed for good:
 * `failed()` is called immediately, `tries` and `backoff()` are never read. Each of these
 * jobs used to throw on a Mollie error so the queue would back off — which on `sync` meant
 * the first error dead-lettered the marker and mailed the admins, with no retry at all.
 *
 * They now leave their marker in place on a sync run, and the scheduled pass that reads
 * the marker is the ladder: the hourly SweepPendingRetriesJob for refund lines, PATCHes and
 * cancels, the nightly PrepareUsageOverageJob for the overage charge. The window each job
 * already has (7 days, 24 hours, three attempts) still ends it, with the one mail.
 *
 * Everything here goes through the real sync queue — dispatch(), not handle() — because
 * that is the only place the behaviour differs.
 */

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('mollie-billing.queue.connection', null);
    config()->set('mollie-billing.billable_model', TestBillable::class);

    Notification::fake();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => []);
});

function syncRetryBillable(array $meta, array $attributes = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Sync '.uniqid(),
        'email' => uniqid('sync-').'@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_sync',
        'mollie_mandate_id' => 'mdt_sync',
    ], $attributes));

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_meta' => $meta,
    ])->save();

    return $b->refresh();
}

function runSweep(): void
{
    SweepPendingRetriesJob::dispatch();
}

const SYNC_REFUND_LINE = [
    'kind' => 'addon',
    'code' => 'extra',
    'parent_invoice_id' => 77,
    'parent_line_item_index' => 0,
    'amount_net' => -500,
];

it('keeps a rejected refund line for the hourly sweep, and dead-letters it once after seven days', function (): void {
    // InvoiceService's contract for a refund Mollie rejects: the line is appended again,
    // stamped "now", and createRefund() returns null.
    $attempts = new ArrayObject(['n' => 0]);
    app()->bind(InvoiceService::class, fn () => new class($attempts) extends InvoiceService
    {
        public function __construct(private ArrayObject $attempts) {}

        public function createRefund(\GraystackIT\MollieBilling\Contracts\Billable $billable, array $refundLines, ?string $description = null): ?\GraystackIT\MollieBilling\Models\BillingInvoice
        {
            $this->attempts['n']++;
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['pending_refund_retries'] = array_merge((array) ($meta['pending_refund_retries'] ?? []), [[
                'line' => SYNC_REFUND_LINE,
                'error' => 'Mollie 503',
                'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
            ]]);
            $billable->forceFill(['subscription_meta' => $meta])->save();

            return null;
        }
    });

    $firstFailure = BillingTime::nowUtc()->subHour()->toIso8601String();
    $b = syncRetryBillable(['pending_refund_retries' => [[
        'line' => SYNC_REFUND_LINE, 'error' => 'Mollie 503', 'first_attempt_at' => $firstFailure,
    ]]]);

    runSweep();

    $entries = $b->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [];

    // Tried, rejected, kept — with the first failure's time, so the window keeps counting.
    expect($attempts['n'])->toBe(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['first_attempt_at'])->toBe($firstFailure)
        ->and($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_refund_retries_dead_letter');
    Notification::assertNothingSent();

    // The next hours: tried again each time, still nobody mailed.
    foreach ([1, 2, 30] as $hours) {
        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00', 'UTC')->addHours($hours));
        runSweep();
    }

    expect($attempts['n'])->toBe(4)
        ->and($b->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0]['first_attempt_at'])->toBe($firstFailure);
    Notification::assertNothingSent();

    // Seven days after the first failure: the dead letter, one mail, nothing left to sweep.
    Carbon::setTestNow(Carbon::parse('2027-03-17 09:00:00', 'UTC'));
    runSweep();
    runSweep();

    $meta = $b->fresh()->getBillingSubscriptionMeta();

    expect($attempts['n'])->toBe(4)
        ->and($meta['pending_refund_retries'] ?? [])->toBe([])
        ->and($meta['pending_refund_retries_dead_letter'] ?? [])->toHaveCount(1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

it('keeps a failed Mollie PATCH for the hourly sweep, and gives up once after 24 hours', function (): void {
    $calls = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function () use (&$calls) {
        $calls++;

        throw MollieApiExceptionFactory::withStatus(503, 'Service unavailable');
    });

    $firstFailure = BillingTime::nowUtc()->subMinutes(10)->toIso8601String();
    $b = syncRetryBillable([
        'mollie_subscription_id' => 'sub_sync',
        'pending_subscription_patch' => [
            'reason' => 'next_charge_date',
            'target' => '2027-04-20T00:00:00+00:00',
            'first_attempt_at' => $firstFailure,
            'last_error' => 'Mollie 503',
        ],
    ]);

    runSweep();

    $marker = $b->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    expect($calls)->toBe(1)
        ->and($marker)->toBeArray()
        ->and($marker['first_attempt_at'])->toBe($firstFailure)
        ->and($marker['target'])->toBe('2027-04-20T00:00:00+00:00')
        ->and($marker['last_error'])->toContain('Service unavailable');
    Notification::assertNothingSent();

    Carbon::setTestNow(Carbon::parse('2027-03-10 15:00:00', 'UTC'));
    runSweep();

    expect($calls)->toBe(2)
        ->and($b->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch']['first_attempt_at'])->toBe($firstFailure);
    Notification::assertNothingSent();

    // Past the 24 hours: given up — marker gone, one mail, no further PATCH.
    Carbon::setTestNow(Carbon::parse('2027-03-11 10:00:00', 'UTC'));
    runSweep();
    runSweep();

    expect($calls)->toBe(2)
        ->and($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

it('keeps a failed Mollie cancel for the hourly sweep, and gives up once after 24 hours', function (): void {
    $calls = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function () use (&$calls) {
        $calls++;

        throw MollieApiExceptionFactory::withStatus(500, 'Internal error');
    });

    $firstFailure = BillingTime::nowUtc()->subMinutes(10)->toIso8601String();
    $b = syncRetryBillable([
        'pending_subscription_cancel' => [
            'mollie_customer_id' => 'cst_sync',
            'mollie_subscription_id' => 'sub_sync',
            'first_attempt_at' => $firstFailure,
            'last_error' => 'Mollie 500',
        ],
    ], ['subscription_status' => SubscriptionStatus::Cancelled]);

    runSweep();

    expect($calls)->toBe(1)
        ->and($b->fresh()->getBillingSubscriptionMeta()['pending_subscription_cancel']['first_attempt_at'] ?? null)->toBe($firstFailure);
    Notification::assertNothingSent();

    Carbon::setTestNow(Carbon::parse('2027-03-10 20:00:00', 'UTC'));
    runSweep();

    expect($calls)->toBe(2);
    Notification::assertNothingSent();

    Carbon::setTestNow(Carbon::parse('2027-03-11 10:00:00', 'UTC'));
    runSweep();
    runSweep();

    expect($calls)->toBe(2)
        ->and($b->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_cancel');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

it('leaves an overage charge Mollie would not create for the nightly pass, and mails after three nights', function (): void {
    $b = syncRetryBillable([
        'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
    ]);

    // The create-payment call fails before a payment exists: nothing was charged, so the
    // dunning budget is untouched — only the attempts count.
    $service = Mockery::mock(ChargeUsageOverageDirectly::class)->makePartial();
    $service->shouldReceive('pendingCharge')->andReturn([]);
    $service->shouldReceive('retry')->times(3)->andThrow(new RuntimeException('Mollie 503 on create payment'));
    app()->instance(ChargeUsageOverageDirectly::class, $service);

    // Night one and two: the pass dispatches, the charge fails, nothing is mailed and the
    // snapshot stays pending for the next night.
    RetryUsageOverageChargeJob::dispatch(TestBillable::class, $b->getKey());
    Carbon::setTestNow(Carbon::parse('2027-03-11 03:00:00', 'UTC'));
    RetryUsageOverageChargeJob::dispatch(TestBillable::class, $b->getKey());

    expect($b->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($b->fresh()->getBillingSubscriptionMeta()[RetryUsageOverageChargeJob::INLINE_FAILURES_KEY])->toBe(2);
    Notification::assertNothingSent();

    // Night three: as many failures as a worker would have made — the operator is told.
    Carbon::setTestNow(Carbon::parse('2027-03-12 03:00:00', 'UTC'));
    expect(fn () => RetryUsageOverageChargeJob::dispatch(TestBillable::class, $b->getKey()))
        ->toThrow(RuntimeException::class, 'Mollie 503 on create payment');

    Notification::assertSentToTimes(new AnonymousNotifiable, AdminOverageBillingFailedNotification::class, 1);

    // The count starts over, and the snapshot is still pending for the pass after that.
    expect($b->fresh()->getBillingSubscriptionMeta()[RetryUsageOverageChargeJob::INLINE_FAILURES_KEY])->toBe(0)
        ->and($b->fresh()->getBillingSubscriptionMeta()['usage_overage_status'])->toBe(ChargeUsageOverageDirectly::STATUS_PENDING);
});
