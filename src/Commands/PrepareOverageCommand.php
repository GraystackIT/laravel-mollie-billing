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
        PrepareUsageOverageJob::dispatchSync();

        return self::SUCCESS;
    }
}
