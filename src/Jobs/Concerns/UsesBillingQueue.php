<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs\Concerns;

/**
 * Routes the job onto the package's configured queue and defers it until the
 * surrounding transaction commits.
 *
 * Apply by composing the trait into a `ShouldQueue` job (or notification) and calling
 * `$this->initializeBillingQueue()` from the constructor. With null/empty config values
 * the job falls back to the framework default.
 */
trait UsesBillingQueue
{
    protected function initializeBillingQueue(): void
    {
        $connection = config('mollie-billing.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $queue = config('mollie-billing.queue.name');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        // Almost everything here is dispatched from inside a DB::transaction — the
        // webhook handlers, the plan-change paths, the refund service. Without this a
        // worker can dequeue before the commit and lose the race twice over: an
        // InvoiceAvailableNotification whose invoice row does not exist yet fails
        // `firstOrFail()` and is dropped straight into failed_jobs (SerializesModels,
        // no retry), and a RetrySubscriptionPatchJob PATCHes Mollie to a plan the
        // transaction then rolls back — so Mollie collects a price the customer was
        // never moved to. It is opt-in per dispatch in Laravel; making it the default
        // here is the only way to cover the app-registered replacement notifications too.
        $this->afterCommit = true;
    }
}
