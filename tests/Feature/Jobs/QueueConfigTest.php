<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Jobs\ApplyScheduledChangesJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\PruneProcessedWebhooksJob;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Jobs\RetryUsageOverageChargeJob;
use GraystackIT\MollieBilling\Jobs\RevokeMollieMandateJob;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Testing\TestBillable;

it('routes all package jobs onto the configured queue', function (): void {
    config()->set('mollie-billing.queue.connection', 'redis');
    config()->set('mollie-billing.queue.name', 'billing-custom');

    $jobs = [
        new ApplyScheduledChangesJob(TestBillable::class, 1),
        new CleanupStalePendingProrataChangeJob(),
        new PrepareUsageOverageJob(),
        new PruneProcessedWebhooksJob(),
        new RetryRefundLineJob(TestBillable::class, 1, [], '2026-01-01T00:00:00+00:00'),
        new RetrySubscriptionPatchJob([], '2026-01-01T00:00:00+00:00'),
        new RetryUsageOverageChargeJob(TestBillable::class, 1),
        new RevokeMollieMandateJob('cst_x', 'mdt_x'),
        new SyncSeatsJob(TestBillable::class, 1, 5),
    ];

    foreach ($jobs as $job) {
        expect($job->connection)->toBe('redis', $job::class.' connection');
        expect($job->queue)->toBe('billing-custom', $job::class.' queue');
    }
});

it('falls back to framework default when queue config is null', function (): void {
    config()->set('mollie-billing.queue.connection', null);
    config()->set('mollie-billing.queue.name', null);

    $job = new RevokeMollieMandateJob('cst_x', 'mdt_x');

    expect($job->connection)->toBeNull();
    expect($job->queue)->toBeNull();
});

it('falls back to framework default when queue config is empty string', function (): void {
    config()->set('mollie-billing.queue.connection', '');
    config()->set('mollie-billing.queue.name', '');

    $job = new RevokeMollieMandateJob('cst_x', 'mdt_x');

    expect($job->connection)->toBeNull();
    expect($job->queue)->toBeNull();
});

it('routes the queued PlanChangeFailedNotification onto the configured queue', function (): void {
    config()->set('mollie-billing.queue.connection', 'sqs');
    config()->set('mollie-billing.queue.name', 'billing-prio');

    $billable = TestBillable::create([
        'name' => 'X',
        'email' => 'x@example.test',
    ]);

    $notification = new PlanChangeFailedNotification($billable, 'tr_x');

    expect($notification->connection)->toBe('sqs');
    expect($notification->queue)->toBe('billing-prio');
});
