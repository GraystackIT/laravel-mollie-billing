<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs\Concerns;

/**
 * Routes the job onto the package's configured queue.
 *
 * Apply by composing the trait into a `ShouldQueue` job and calling
 * `$this->initializeBillingQueue()` from the constructor. With null/empty
 * config values the job falls back to the framework default.
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
    }
}
