<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Services\Vat\OssProtocolService;
use Illuminate\Console\Command;

class OssExportCommand extends Command
{
    protected $signature = 'billing:oss-export {year}';

    protected $description = 'Export the OSS (One-Stop Shop) VAT protocol for the given year.';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $path = app(OssProtocolService::class)->export($year);
        $this->info("OSS export written to {$path}");

        return self::SUCCESS;
    }
}
