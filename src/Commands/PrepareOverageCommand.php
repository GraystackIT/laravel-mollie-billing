<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use Illuminate\Console\Command;

class PrepareOverageCommand extends Command
{
    protected $signature = 'billing:prepare-overage';

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

        PrepareUsageOverageJob::dispatchSync();

        $this->info("Overage preparation finished for [{$billableClass}].");

        return self::SUCCESS;
    }
}
