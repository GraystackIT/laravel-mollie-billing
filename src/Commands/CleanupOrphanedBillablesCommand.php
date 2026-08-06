<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use Illuminate\Console\Command;

class CleanupOrphanedBillablesCommand extends Command
{
    protected $signature = 'billing:cleanup-orphans
        {--dry-run : List the billables that would be deleted without deleting anything}
        {--force : Skip the confirmation prompt (for cron / CI)}';

    protected $description = 'Delete billables that were created during checkout but never reached an active subscription.';

    public function handle(): int
    {
        // Every one of these makes the job a no-op. Reporting SUCCESS with no output
        // told the operator the cleanup ran when nothing had happened.
        if (! (bool) config('mollie-billing.cleanup.enabled', true)) {
            $this->warn('Cleanup is disabled (mollie-billing.cleanup.enabled = false) — nothing was done.');

            return self::FAILURE;
        }

        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            $this->error('mollie-billing.billable_model is not set to an existing class — nothing to clean up.');

            return self::FAILURE;
        }

        $thresholdMinutes = (int) config('mollie-billing.cleanup.threshold_minutes', 60);

        if ($thresholdMinutes <= 0) {
            $this->error("cleanup.threshold_minutes is {$thresholdMinutes} — must be > 0. Nothing was done.");

            return self::FAILURE;
        }

        $candidates = CleanupOrphanedBillablesJob::candidateCount();

        $this->line("Candidates older than {$thresholdMinutes} minutes without an accessible subscription: {$candidates}");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no billable was deleted. Candidates still pass the paid-invoice and Mollie guards at delete time.');

            return self::SUCCESS;
        }

        if ($candidates === 0) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        // This command deletes rows (and revokes Mollie mandates) via the app's
        // cleanup closure, so it must not do that unprompted.
        if (! $this->option('force') && ! $this->confirm("Delete up to {$candidates} orphaned billable(s) and revoke their Mollie mandates?", false)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        CleanupOrphanedBillablesJob::dispatchSync();

        $remaining = CleanupOrphanedBillablesJob::candidateCount();

        $this->info(sprintf(
            'Cleanup finished — %d of %d candidate(s) removed, %d kept by the paid-money guards.',
            $candidates - $remaining,
            $candidates,
            $remaining,
        ));

        return self::SUCCESS;
    }
}
