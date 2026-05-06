<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Jobs\AutoResolveCountryMismatchesJob;
use Illuminate\Console\Command;

class AutoResolveMismatchesCommand extends Command
{
    protected $signature = 'billing:auto-resolve-mismatches';

    protected $description = 'Run the country mismatch auto-resolve sweep (refund + reissue with corrected VAT).';

    public function handle(): int
    {
        AutoResolveCountryMismatchesJob::dispatchSync();

        return self::SUCCESS;
    }
}
