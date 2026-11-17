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
        /** How often this change has already been put back because the seat lock was busy. */
        public readonly int $requeues = 0,
        /** How often it has been put back to wait for another change's payment. */
        public readonly int $waits = 0,
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
        //
        // A copy put back on the queue is never a duplicate: it is dispatched from inside
        // the run it continues, while that run still holds the unique lock — keyed on the
        // seat count, an absolute copy was dropped right there and the change was lost.
        return $this->delta !== null || $this->requeues > 0 || $this->waits > 0
            ? $this->billableClass.':'.$this->billableId.':seats:'.$this->token
            : $this->billableClass.':'.$this->billableId.':seats:'.$this->seats;
    }

    /** Serialises seat jobs for one billable against each other — see handle(). */
    public const LOCK_PREFIX = 'billing:seat-sync:';

    /** How long a seat job waits for the one ahead of it before giving up. */
    public const LOCK_WAIT_SECONDS = 30;

    /**
     * How often a seat change is put back on the queue when the lock stays busy.
     *
     * Waiting out the lock used to end in a `LockTimeoutException`, and with `tries = 1`
     * that was the end of the seat change: a `failed_jobs` row, nobody told, a member
     * invited and a seat never billed. Nothing has happened at that point — no Mollie
     * call, no write — so the change can simply be queued again. Bounded, so a lock that
     * is never released (it expires on its own after `timeout + 60` seconds) cannot turn
     * into an endless loop; past the bound the job fails as before, which by then says
     * something is genuinely wrong.
     */
    public const MAX_REQUEUES = 5;

    /** Seconds before a put-back seat change is tried again. */
    public const REQUEUE_DELAY_SECONDS = 60;

    /**
     * A seat change that meets another change still waiting for its payment.
     *
     * `ValidateSubscriptionChange` allows one change in flight per subscription, so a second
     * "member invited" inside the confirmation window of the first — a card payment takes
     * seconds, SEPA days — was refused with `InvalidSubscriptionStateException`, and with
     * `tries = 1` the member's seat was never billed. Nothing has happened at that point, so
     * the change waits: put back every ten minutes for up to seven days, the time after which
     * CleanupStalePendingProrataChangeJob settles an unanswered change for good. Past that the
     * job fails as before.
     */
    public const PENDING_WAIT_SECONDS = 600;

    public const MAX_PENDING_WAITS = 1008;

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

        try {
            $lock->block($this->lockWaitSeconds());
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            if ($this->requeues >= self::MAX_REQUEUES) {
                throw $e;
            }

            // A new job rather than `release()`: releasing counts against `tries = 1`.
            // The copy carries the same change — the delta is resolved when it runs.
            self::dispatch($this->billableClass, $this->billableId, $this->seats, $this->delta, $this->requeues + 1, $this->waits)
                ->delay(self::REQUEUE_DELAY_SECONDS);

            return;
        }

        try {
            $this->run($service, $class);
        } finally {
            $lock->release();
        }
    }

    /** Overridable so a test does not have to sit out the real wait. */
    protected function lockWaitSeconds(): int
    {
        return self::LOCK_WAIT_SECONDS;
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

        try {
            $service->handle($billable, $target);
        } catch (\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException $e) {
            if (! $this->waitsForPendingChange($billable)) {
                throw $e;
            }

            self::dispatch($this->billableClass, $this->billableId, $this->seats, $this->delta, $this->requeues, $this->waits + 1)
                ->delay(self::PENDING_WAIT_SECONDS);
        }
    }

    /**
     * Whether the refusal was "another change is waiting for its payment", and this change
     * may wait for it. Not on a sync run: a delay is ignored there, so the copy would run at
     * once against the same pending change, again and again — the job fails as before, and
     * `billing:check-config` warns about the sync driver.
     */
    private function waitsForPendingChange(Model $billable): bool
    {
        if ($this->waits >= self::MAX_PENDING_WAITS || \GraystackIT\MollieBilling\Support\BillingQueue::isSyncRun($this->job)) {
            return false;
        }

        /** @var Billable&Model $billable */
        $meta = $billable->refresh()->getBillingSubscriptionMeta();

        return ! empty($meta['pending_plan_change']) || ! empty($meta['pending_prorata_change']);
    }
}
