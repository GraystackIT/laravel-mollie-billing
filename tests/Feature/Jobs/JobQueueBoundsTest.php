<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Jobs\ApplyScheduledChangesJob;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingCountryCorrectionJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionCancelJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * Total seconds a backoff ladder spans for `$tries` attempts. Laravel repeats the last
 * delay for every attempt past the end of the array.
 *
 * @param  array<int, int>  $backoff
 */
function ladderSeconds(array $backoff, int $tries): int
{
    $total = 0;

    for ($delay = 0; $delay < $tries - 1; $delay++) {
        $total += $backoff[$delay] ?? end($backoff);
    }

    return $total;
}

/**
 * Every job class the package ships that is `ShouldBeUnique`.
 *
 * Enumerated from the directory rather than listed, so a new job cannot join the package
 * without the bounds below applying to it.
 *
 * @return array<int, class-string>
 */
function uniqueJobClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../../src/Jobs/*.php') ?: [] as $file) {
        $class = 'GraystackIT\\MollieBilling\\Jobs\\'.basename($file, '.php');

        if (is_a($class, \Illuminate\Contracts\Queue\ShouldBeUnique::class, true)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('holds every unique lock past the runtime the job caps for itself', function (): void {
    // Laravel takes the unique lock at *dispatch* time, not when the worker picks the job
    // up. So `uniqueFor` has to cover the queue wait plus the whole run: a lock equal to
    // `timeout` is already expired by the time a job that ran to its own limit finishes,
    // and the next dispatching pass — which finds its row for as long as the marker it
    // clears on success is set — queues a second copy of work that spends money.
    //
    // Read off the class defaults so no constructor arguments are needed, and so the rule
    // covers every job in `src/Jobs` rather than a list that ages.
    foreach (uniqueJobClasses() as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        $uniqueFor = $defaults['uniqueFor'] ?? null;
        $timeout = $defaults['timeout'] ?? null;

        if (! is_int($uniqueFor) || ! is_int($timeout)) {
            // A job that caps neither, or caps only one of the two, is covered by the
            // ladder assertions further down instead.
            continue;
        }

        expect($uniqueFor)->toBeGreaterThan(
            $timeout,
            $class.' unique lock expires before its own timeout, so a second copy can be queued mid-run',
        );
    }
});

it('bounds every job that walks the whole billable table', function (): void {
    // A table scan with a synchronous Mollie call per row and no timeout runs until the
    // worker's default cuts it off mid-pass. Combined with `tries = 1` that silently
    // dropped everything after the kill point for a whole day.
    foreach ([
        new PrepareUsageOverageJob,
        new SweepPendingRetriesJob,
        new CleanupOrphanedBillablesJob,
        new CleanupStalePendingProrataChangeJob,
        new CleanupStalePendingCountryCorrectionJob,
    ] as $job) {
        expect($job->timeout ?? null)
            ->toBeGreaterThan(0, $job::class.' must cap its own runtime');
    }
});

it('retries the daily overage pass so a kill cannot skip its later passes', function (): void {
    $job = new PrepareUsageOverageJob;

    // Every pass is idempotent on a second run, so a retry is safe — and it is the only
    // thing that gets the expiry, auto-cancel and scheduled-change passes to run at all
    // after the charge pass was killed.
    expect($job->tries)->toBeGreaterThan(1);
    expect($job->timeout)->toBeGreaterThan(0);
});

it('pins ApplyScheduledChangesJob to a single, non-duplicable attempt', function (): void {
    $job = new ApplyScheduledChangesJob(TestBillable::class, 1);

    // apply() creates Mollie charges and refunds and only clears `scheduled_change_at`
    // on success, so a worker started with --tries=3 would issue the money call twice.
    expect($job->tries)->toBe(1);

    // …and for the same reason the dispatching pass must not be able to queue a second
    // apply() for a billable whose first one has not committed yet.
    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
    expect($job->uniqueId())->toContain('scheduled-change');
    expect($job->uniqueFor)->toBeGreaterThan(0);
});

it('holds every unique lock past the end of its own retry ladder', function (): void {
    $now = BillingTime::nowUtc()->toIso8601String();

    $jobs = [
        new RetrySubscriptionPatchJob(['billable_type' => TestBillable::class, 'billable_id' => 1], $now),
        new RetrySubscriptionCancelJob(TestBillable::class, 1, 'cst_x', 'sub_x', $now),
        new RetryRefundLineJob(TestBillable::class, 1, ['parent_invoice_id' => 1, 'parent_line_item_index' => 0], $now),
    ];

    foreach ($jobs as $job) {
        // A lock that expires mid-ladder lets the hourly sweep start a second chain,
        // which gives up at once — clearing the marker and mailing the admins, with the
        // original chain mailing them again later.
        expect($job->uniqueFor)
            ->toBeGreaterThan(ladderSeconds($job->backoff(), $job->tries), $job::class.' unique lock is shorter than its ladder');
    }
});

it('gives the hourly sweep headroom below its own schedule', function (): void {
    $job = new SweepPendingRetriesJob;

    $worstCase = $job->timeout * $job->tries + ladderSeconds([$job->backoff], $job->tries);

    expect($job->uniqueFor)->toBeGreaterThan($worstCase);
    // …and still short enough that a lock orphaned by a crashed worker clears before the
    // run after next.
    expect($job->uniqueFor)->toBeLessThan(3600);
});
