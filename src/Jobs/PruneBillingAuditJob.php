<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Enforces `mollie-billing.audit.retention_days`.
 *
 * Deliberately not spatie's `activitylog:clean`: that command prunes the whole
 * activity_log table from a global config key, which would also delete the app's
 * own activity rows. We only ever touch our own log_name.
 */
class PruneBillingAuditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function handle(): void
    {
        $days = config('mollie-billing.audit.retention_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return; // null / 0 means keep forever
        }

        BillingAuditEntry::model()::query()
            ->where('log_name', config('mollie-billing.audit.log_name', 'billing'))
            ->where('created_at', '<', BillingTime::nowUtc()->subDays((int) $days))
            ->delete();
    }
}
