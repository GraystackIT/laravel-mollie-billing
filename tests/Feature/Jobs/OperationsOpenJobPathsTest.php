<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Job paths that were reported as open in earlier rounds, walked to their end:
 * the hourly sweep with the two flat shapes of `pending_subscription_patch` through
 * `RetrySubscriptionPatchJob`, and a seat job that cannot get its lock.
 */
beforeEach(function (): void {
    Notification::fake();
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.vat.seller_country', 'AT');

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 3, 'feature_keys' => [], 'allowed_addons' => ['softdrinks'],
        'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 990, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons.softdrinks', [
        'name' => 'Softdrinks', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 490]],
    ]);
});

function openJobPathsBillable(array $attributes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Open Paths Co',
        'email' => uniqid('open-paths-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'mollie_customer_id' => 'cst_open',
        'mollie_mandate_id' => 'mdt_open',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => ['softdrinks'],
        'subscription_meta' => ['seat_count' => 3, 'mollie_subscription_id' => 'sub_open'],
    ], $attributes))->save();

    return $billable->refresh();
}

/** What an UpdateSubscriptionRequest carries, read without knowing its internals. */
function openJobPathsPatch(UpdateSubscriptionRequest $request): array
{
    $read = function (string $property) use ($request): mixed {
        $reflection = new ReflectionObject($request);

        if (! $reflection->hasProperty($property)) {
            return null;
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($request);
    };

    $amount = $read('amount');
    $start = $read('startDate');

    return [
        'amount' => $amount === null ? null : (string) $amount->value,
        'start' => $start === null ? null : (string) $start,
    ];
}

// ── SweepPendingRetriesJob → RetrySubscriptionPatchJob ──────────────────────

it('sweeps a next_charge_date marker through to Mollie and clears it', function (): void {
    $target = BillingTime::nowUtc()->addDays(40)->startOfDay();

    $billable = openJobPathsBillable();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_subscription_patch'] = [
        'reason' => 'next_charge_date',
        'target' => $target->toIso8601String(),
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(30)->toIso8601String(),
        'last_error' => 'Mollie 503',
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $patches = [];
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$patches): object {
        $patches[] = openJobPathsPatch($request);

        return (object) ['id' => 'sub_open'];
    });

    SweepPendingRetriesJob::dispatchSync();

    // Mollie now holds the date the customer was already shown — and only the date.
    expect($patches)->toBe([['amount' => null, 'start' => $target->toDateString()]])
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch');
});

it('keeps a next_charge_date marker while Mollie is still unreachable', function (): void {
    $billable = openJobPathsBillable();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_subscription_patch'] = [
        'reason' => 'next_charge_date',
        'target' => BillingTime::nowUtc()->addDays(40)->toIso8601String(),
        'first_attempt_at' => BillingTime::nowUtc()->subMinutes(30)->toIso8601String(),
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('send')->andThrow(new RuntimeException('Mollie 503'));

    // On a real queue: the sweep dispatches, the worker runs the job, the job throws and
    // the queue backs off. (The `sync` driver would call `failed()` at once and dead-letter
    // on the first error — which is why this runs the dispatched job by hand.)
    Bus::fake([\GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob::class]);
    SweepPendingRetriesJob::dispatchSync();

    $job = Bus::dispatched(\GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob::class)->sole();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Mollie 503');

    // The marker is what the next attempt, and the next sweep, find.
    expect($billable->fresh()->getBillingSubscriptionMeta()['pending_subscription_patch']['reason'] ?? null)
        ->toBe('next_charge_date');
});

it('sweeps a plan_waiver_sync marker: Mollie ends up charging what the waiver leaves', function (): void {
    $billable = openJobPathsBillable();

    // The plan is made free for three periods, but the PATCH that follows does not land.
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('Mollie 503'));

    app(AdminExtensionService::class)->freePlanPeriods($billable, 3);

    $billable->refresh();

    expect(app(PlanWaiver::class)->marker($billable))->not->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_subscription_patch']['reason'] ?? null)->toBe('plan_waiver_sync');

    // An hour later Mollie answers again.
    $patches = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$patches): object {
        if ($request instanceof UpdateSubscriptionRequest) {
            $patches[] = openJobPathsPatch($request);
        }

        return (object) ['id' => 'sub_open'];
    });

    SweepPendingRetriesJob::dispatchSync();

    // The plan (29.00) is waived; the addon stays: 4.90 + 20 % = 5.88.
    expect($patches)->toHaveCount(1)
        ->and($patches[0]['amount'])->toBe('5.88')
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_patch')
        // The waiver itself was neither undone nor duplicated by the retry.
        ->and(app(PlanWaiver::class)->marker($billable->fresh())['max_applications'])->toBe(3);
});

// ── SyncSeatsJob ────────────────────────────────────────────────────────────

/** The job with a wait short enough to test: the lock is either free at once or not. */
class OpenJobPathsImpatientSeatJob extends SyncSeatsJob
{
    protected function lockWaitSeconds(): int
    {
        return 0;
    }
}

it('puts a seat change back on the queue when another one holds the lock', function (): void {
    Bus::fake();

    $billable = openJobPathsBillable();
    $held = Cache::lock(SyncSeatsJob::LOCK_PREFIX.TestBillable::class.':'.$billable->getKey(), 60);
    expect($held->get())->toBeTrue();

    $service = Mockery::mock(SyncSeats::class);
    $service->shouldNotReceive('handle');

    (new OpenJobPathsImpatientSeatJob(TestBillable::class, $billable->getKey(), 0, 1))->handle($service);

    // Nothing was written and nothing was lost: the same +1 is queued again, a minute on.
    // (The copy is of the job's own class — here the impatient test double.)
    Bus::assertDispatched(OpenJobPathsImpatientSeatJob::class, function (SyncSeatsJob $job) use ($billable): bool {
        return $job->delta === 1
            && $job->requeues === 1
            && (string) $job->billableId === (string) $billable->getKey()
            && $job->delay === SyncSeatsJob::REQUEUE_DELAY_SECONDS;
    });

    expect($billable->fresh()->getBillingSeatCount())->toBe(3);

    $held->release();
});

it('gives up on a seat lock that never frees, loudly', function (): void {
    Bus::fake();

    $billable = openJobPathsBillable();
    $held = Cache::lock(SyncSeatsJob::LOCK_PREFIX.TestBillable::class.':'.$billable->getKey(), 60);
    $held->get();

    $job = new OpenJobPathsImpatientSeatJob(TestBillable::class, $billable->getKey(), 0, 1, SyncSeatsJob::MAX_REQUEUES);

    expect(fn () => $job->handle(Mockery::mock(SyncSeats::class)))->toThrow(LockTimeoutException::class);

    Bus::assertNotDispatched(OpenJobPathsImpatientSeatJob::class);

    $held->release();
});

it('runs the seat change once the lock is free again', function (): void {
    $billable = openJobPathsBillable();

    $targets = [];
    $service = Mockery::mock(SyncSeats::class);
    $service->shouldReceive('handle')->once()->andReturnUsing(function ($b, int $target) use (&$targets): void {
        $targets[] = $target;
    });

    // The copy that was put back, arriving when nobody holds the lock.
    (new OpenJobPathsImpatientSeatJob(TestBillable::class, $billable->getKey(), 0, 1, 1))->handle($service);

    expect($targets)->toBe([4]);
});

it('waits for another change\'s payment instead of losing the seat', function (string $mode): void {
    Bus::fake();
    Mollie::shouldReceive('send')->never();

    // The first "member invited" posted a prorata payment that is not confirmed yet. The
    // second one is refused by ValidateSubscriptionChange ("already pending payment
    // confirmation") — and with tries = 1 that used to be the end of the second seat.
    $billable = openJobPathsBillable([
        'subscription_meta' => [
            'seat_count' => 4,
            'mollie_subscription_id' => 'sub_open',
            'pending_prorata_change' => ['charge_payment_id' => 'tr_first_seat', 'created_at' => BillingTime::nowUtc()->toIso8601String()],
        ],
    ]);

    $job = $mode === 'delta'
        ? SyncSeatsJob::byDelta(TestBillable::class, $billable->getKey(), 1)
        : new SyncSeatsJob(TestBillable::class, $billable->getKey(), 5);

    // The running job holds its own unique lock while it puts the copy back — keyed on the
    // seat count, an absolute copy was dropped right here.
    $unique = Cache::lock(\Illuminate\Bus\UniqueLock::getKey($job), 900);
    expect($unique->get())->toBeTrue();

    $job->handle(app(SyncSeats::class));

    Bus::assertDispatched(SyncSeatsJob::class, function (SyncSeatsJob $copy) use ($job): bool {
        return $copy->delta === $job->delta
            && $copy->seats === $job->seats
            && $copy->waits === 1
            && $copy->requeues === 0
            && $copy->delay === SyncSeatsJob::PENDING_WAIT_SECONDS;
    });

    expect($billable->fresh()->getBillingSeatCount())->toBe(4);

    $unique->release();
})->with(['delta', 'absolute']);

it('stops waiting for a pending change after seven days, loudly', function (): void {
    Bus::fake();
    Mollie::shouldReceive('send')->never();

    $billable = openJobPathsBillable([
        'subscription_meta' => [
            'seat_count' => 4,
            'mollie_subscription_id' => 'sub_open',
            'pending_prorata_change' => ['charge_payment_id' => 'tr_first_seat'],
        ],
    ]);

    $job = new SyncSeatsJob(TestBillable::class, $billable->getKey(), 0, 1, 0, SyncSeatsJob::MAX_PENDING_WAITS);

    expect(fn () => $job->handle(app(SyncSeats::class)))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException::class);

    Bus::assertNotDispatched(SyncSeatsJob::class);
});

it('does not wait on a refusal that is not about a pending payment', function (): void {
    Bus::fake();

    $billable = openJobPathsBillable(['subscription_status' => SubscriptionStatus::Expired]);

    $service = Mockery::mock(SyncSeats::class);
    $service->shouldReceive('handle')->once()->andThrow(new \GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException('Subscription has ended.'));

    expect(fn () => SyncSeatsJob::byDelta(TestBillable::class, $billable->getKey(), 1)->handle($service))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException::class, 'Subscription has ended.');

    Bus::assertNotDispatched(SyncSeatsJob::class);
});
