<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Applies a seat-count change asynchronously.
 *
 * Prefer {@see byDelta()} over the absolute constructor. An absolute seat count is
 * captured at dispatch time, so two concurrent changes ("member invited" twice) both
 * compute their target from the same stale read and the later job silently discards
 * the earlier one's seat. A delta is resolved inside the job, under the billable's row
 * lock — the same read-modify-write the addon services use.
 *
 * ShouldBeUnique per billable on top of that: the lock serialises overlapping runs,
 * uniqueness keeps a duplicate of the *same* pending change from being queued twice.
 */
class SyncSeatsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $uniqueFor = 900;

    /**
     * One attempt, never the worker's `--tries` default — the same reason
     * ApplyScheduledChangesJob states: handle() runs UpdateSubscription, which creates
     * Mollie prorata payments and PATCHes the subscription inside a transaction, so a
     * transient failure after the money moved rolls the local seat count back while the
     * charge stands. A second attempt would charge again — and in byDelta() mode it is
     * worse than a repeat, because the delta is re-resolved from the rolled-back row: the
     * retry adds *another* seat. Uniqueness cannot catch it either, since a delta job's
     * uniqueId() is deliberately per-dispatch.
     */
    public int $tries = 1;

    /** Long enough for the Mollie round-trips of one seat change. */
    public int $timeout = 120;

    /** Distinguishes otherwise identical delta jobs — see uniqueId(). */
    public readonly string $token;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public readonly int $seats,
        public readonly ?int $delta = null,
    ) {
        // Generated at construction, not in uniqueId(): the id must survive
        // serialisation, otherwise the worker would release a lock it never took.
        $this->token = bin2hex(random_bytes(8));

        $this->initializeBillingQueue();
    }

    /**
     * Race-free variant: `+1` when a member is added, `-1` when one is removed. The
     * absolute target is computed from the locked row rather than from whatever the
     * dispatcher happened to read.
     */
    public static function byDelta(string $billableClass, string|int $billableId, int $delta): self
    {
        return new self($billableClass, $billableId, 0, $delta);
    }

    public function uniqueId(): string
    {
        // Deltas must never collapse into one another — two "+1"s are two seats. Only
        // absolute targets are deduplicated, where a repeat is genuinely a no-op.
        return $this->delta !== null
            ? $this->billableClass.':'.$this->billableId.':seats:'.$this->token
            : $this->billableClass.':'.$this->billableId.':seats:'.$this->seats;
    }

    public function handle(SyncSeats $service): void
    {
        /** @var class-string $class */
        $class = $this->billableClass;

        DB::transaction(function () use ($class, $service): void {
            // Scope-free — see ApplyScheduledChangesJob.
            /** @var (Billable&Model)|null $billable */
            $billable = $class::withoutGlobalScope(BillingScope::class)
                ->whereKey($this->billableId)
                ->lockForUpdate()
                ->first();

            if ($billable === null) {
                return;
            }

            $target = $this->delta === null
                ? $this->seats
                : $billable->getBillingSeatCount() + $this->delta;

            $service->handle($billable, max(0, $target));
        });
    }
}
