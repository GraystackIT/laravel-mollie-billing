<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use Illuminate\Console\Command;

class CleanupOrphanedBillablesCommand extends Command
{
    protected $signature = 'billing:cleanup-orphans';

    protected $description = 'Delete billables that were created during checkout but never reached an active subscription.';

    public function handle(): int
    {
        CleanupOrphanedBillablesJob::dispatchSync();

        return self::SUCCESS;
    }
}
