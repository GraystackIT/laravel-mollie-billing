<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * What the queue the package's jobs are routed to does with them.
 *
 * On the `sync` driver a dispatched job runs at once, in the dispatching process, and a
 * job that throws has failed for good: `failed()` runs immediately, `tries` and `backoff()`
 * are never consulted. Every retry job in this package keeps its own marker on the row
 * (`pending_refund_retries`, `pending_subscription_patch`, `pending_subscription_cancel`,
 * the overage snapshot) and has a scheduled pass that dispatches it again — so on `sync`
 * the jobs leave the marker in place instead of throwing, and that pass is their ladder.
 */
final class BillingQueue
{
    /** The connection the package's jobs go to: `mollie-billing.queue.connection`, else `queue.default`. */
    public static function connection(): string
    {
        $connection = config('mollie-billing.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : (string) config('queue.default');
    }

    /**
     * Whether this run of a job is a sync one, where a throw is final.
     *
     * Asked of the run, not of the config: a job handed to a worker is retried whatever the
     * default connection says, and one called directly (a test, an app's own code) is not a
     * queue run at all and keeps throwing to its caller.
     */
    public static function isSyncRun(mixed $queueJob): bool
    {
        return $queueJob instanceof \Illuminate\Queue\Jobs\SyncJob;
    }

    /** Whether a dispatch runs the job at once, in the caller's own process. */
    public static function runsInline(): bool
    {
        $connection = self::connection();

        return (string) config("queue.connections.{$connection}.driver", $connection) === 'sync';
    }
}
