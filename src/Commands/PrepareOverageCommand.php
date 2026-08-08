<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Bus;

class PrepareOverageCommand extends Command
{
    protected $signature = 'billing:prepare-overage {--force : Skip the confirmation prompt}';

    protected $description = 'Prepare usage overage charges for all billable entities.';

    public function handle(): int
    {
        // The job returns immediately when no billable model is configured, so a bare
        // dispatchSync() + SUCCESS reported a run that never touched a single row.
        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            $this->error('mollie-billing.billable_model is not set to an existing class — nothing to prepare.');

            return self::FAILURE;
        }

        // This is not a report. The five passes create real recurring Mollie payments,
        // cancel Mollie subscriptions, expire subscriptions and apply scheduled plan
        // changes — so it asks first, like every other data-mutating command here.
        if (! $this->option('force') && ! $this->confirm(
            "This charges overage, cancels past-due subscriptions and applies scheduled changes for every [{$billableClass}]. Continue?",
            false,
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // The job's OWN unique lock, not a private one. `dispatchSync()` bypasses
        // ShouldBeUnique (the lock lives in PendingDispatch), so a manual run neither took nor
        // respected what the scheduled pass holds — and a key of our own guarded
        // command-against-command only, which is not the dangerous pair: two passes walking the
        // same table issue two CreatePaymentRequests for the same negative balance, and each
        // `paid` webhook books its own invoice and credits the quantity again.
        //
        // Worse, `dispatchSync()` routes through CallQueuedHandler, which calls
        // `UniqueLock::release()` → `forceRelease()` on the way out: the manual run *destroyed*
        // the lock the in-flight scheduled pass was relying on, regardless of ownership. So the
        // body runs through `Bus::dispatchNow()`, which does not pass through that handler.
        $job = new PrepareUsageOverageJob;
        $uniqueLock = new UniqueLock(app(CacheRepository::class));

        if (! $uniqueLock->acquire($job)) {
            $this->error('Another overage run is still in progress — refusing to start a second one.');

            return self::FAILURE;
        }

        try {
            Bus::dispatchNow($job);
        } finally {
            $uniqueLock->release($job);
        }

        $this->info("Overage preparation finished for [{$billableClass}].");

        return self::SUCCESS;
    }
}
