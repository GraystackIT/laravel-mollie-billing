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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Applies a seat-count change asynchronously.
 *
 * Prefer {@see byDelta()} over the absolute constructor. An absolute seat count is
 * captured at dispatch time, so two concurrent changes ("member invited" twice) both
 * compute their target from the same stale read and the later job silently discards
 * the earlier one's seat. A delta is resolved inside the job, under the seat lock that
 * also covers the write — the same read-modify-write the addon services use.
 *
 * ShouldBeUnique per billable on top of that: the seat lock in handle() serialises
 * overlapping runs, uniqueness keeps a duplicate of the *same* pending change from being
 * queued twice.
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

    /** Serialises seat jobs for one billable against each other — see handle(). */
    public const LOCK_PREFIX = 'billing:seat-sync:';

    /** How long a seat job waits for the one ahead of it before giving up. */
    public const LOCK_WAIT_SECONDS = 30;

    public function handle(SyncSeats $service): void
    {
        /** @var class-string $class */
        $class = $this->billableClass;

        // A cache lock, held across the whole read-modify-write, because the row lock no
        // longer can be. `byDelta()` exists so that two "member invited" events add two
        // seats, and it resolves the target from the locked row — but that transaction has
        // to commit before the write, since the write goes through UpdateSubscription and
        // no Mollie call may sit inside a transaction. In that gap a second delta job read
        // the same count and both wrote the same target, silently dropping one of the two
        // seats: exactly the race byDelta() was introduced to close.
        //
        // A different key from `billing:subscription-change:{id}`, which update() takes
        // itself — taking that one here would deadlock against it. Blocking rather than
        // refusing, because unlike a plan change these are meant to accumulate; and if the
        // wait runs out the job fails visibly with `tries = 1`, which is the outcome an
        // operator can act on. Holding a cache lock across the Mollie round trip is the
        // established shape here — update() and RefundInvoiceService both do it.
        $lock = Cache::lock(self::LOCK_PREFIX.$this->billableClass.':'.$this->billableId, $this->timeout + 60);
        $lock->block(self::LOCK_WAIT_SECONDS);

        try {
            $this->run($service, $class);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  class-string  $class
     */
    private function run(SyncSeats $service, string $class): void
    {

        // Decide under the row lock, then act outside it. `SyncSeats` goes on to
        // `UpdateSubscription::update()`, which posts a prorata payment and a Mollie PATCH —
        // and CLAUDE.md's rule is general: a transaction can be rolled back, an HTTP call to a
        // payment provider cannot. Wrapping the whole thing meant a throw after the payment
        // (the PATCH failing, the invoice write, a DB error) rolled back the seat count and the
        // invoice while the money stood at Mollie, collected and unbooked, with `tries = 1` and
        // no marker to retry from. It also degraded update()'s own three-phase transactions to
        // savepoints of one long one and held the row lock across two HTTP round trips,
        // blocking every concurrent writer including the webhook handlers.
        //
        // Nothing is lost by dropping the outer transaction: `update()` serialises on its own
        // `billing:subscription-change:{id}` cache lock.
        $target = DB::transaction(function () use ($class): ?int {
            // Scope-free — see ApplyScheduledChangesJob.
            /** @var (Billable&Model)|null $billable */
            $billable = $class::withoutGlobalScope(BillingScope::class)
                ->whereKey($this->billableId)
                ->lockForUpdate()
                ->first();

            if ($billable === null) {
                return null;
            }

            return max(0, $this->delta === null
                ? $this->seats
                : $billable->getBillingSeatCount() + $this->delta);
        });

        if ($target === null) {
            return;
        }

        /** @var (Billable&Model)|null $billable */
        $billable = $class::withoutGlobalScope(BillingScope::class)
            ->whereKey($this->billableId)
            ->first();

        if ($billable === null) {
            return;
        }

        $service->handle($billable, $target);
    }
}
