<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies a scheduled end-of-period change once its `scheduled_change_at` is reached.
 *
 * Everything here is sized around one fact: `ScheduleSubscriptionChange::apply()` runs
 * UpdateSubscription, which creates Mollie charges and refunds. Running it twice for one
 * billable spends the customer's money twice.
 */
class ApplyScheduledChangesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * Explicitly one attempt, never the worker's `--tries` default: `scheduled_change_at`
     * is only cleared once the whole thing committed, so a transient failure after the
     * money moved but before the commit rolls the local state back — and a second attempt
     * would issue the Mollie call again. The next PrepareUsageOverageJob pass re-dispatches
     * whatever is still scheduled, which is the safe way to retry this.
     */
    public int $tries = 1;

    /**
     * Long enough to cover the Mollie round-trips of one apply(). The dispatching pass
     * finds the row for as long as `scheduled_change_at` is set, so without uniqueness a
     * retried or manually re-run pass would queue a second apply() for a billable whose
     * first one had not committed yet.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return $this->billableClass.':'.$this->billableId.':scheduled-change';
    }

    public function handle(ScheduleSubscriptionChange $service): void
    {
        /** @var class-string $class */
        $class = $this->billableClass;

        // Scope-free: a queue worker has no request or tenant context for an
        // app-defined applyBillingScope() to key off, so a scoped lookup would find
        // nothing and the scheduled change would silently never be applied.
        /** @var Billable|null $billable */
        $billable = $class::withoutGlobalScope(BillingScope::class)->find($this->billableId);

        if ($billable === null) {
            return;
        }

        $service->apply($billable);
    }
}
