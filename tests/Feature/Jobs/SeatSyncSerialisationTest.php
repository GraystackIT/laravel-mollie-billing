<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;

it('holds one serialisation across resolving a seat delta and writing it', function (): void {
    // `byDelta()` exists so that two "member invited" events add two seats. It reads the
    // current count under the row lock — but that transaction now commits before the write
    // happens, because the write goes through UpdateSubscription and no Mollie call may sit
    // inside a transaction. Between the two, a second delta job reads the same count, and
    // both write the same target: one of the two seats is silently dropped, on the one code
    // path whose whole reason to exist is that it cannot be.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Seats Co', 'email' => 'seats@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'subscription_meta' => ['seat_count' => 5],
    ])->save();

    $key = SyncSeatsJob::LOCK_PREFIX.TestBillable::class.':'.$billable->getKey();
    $lockWasHeld = null;

    $double = Mockery::mock(SyncSeats::class);
    $double->shouldReceive('handle')->once()->andReturnUsing(function () use ($key, &$lockWasHeld): void {
        // A second job arriving right here must not be able to take the seat lock.
        $competitor = Cache::lock($key, 10);
        $lockWasHeld = ! $competitor->get();
        if (! $lockWasHeld) {
            $competitor->release();
        }
    });

    (new SyncSeatsJob(TestBillable::class, $billable->getKey(), 0, 1))->handle($double);

    expect($lockWasHeld)->toBeTrue();
});
