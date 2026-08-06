<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support\Concerns;

use GraystackIT\MollieBilling\Scopes\BillingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lost-update-free read-modify-write on `subscription_meta`.
 *
 * SweepPendingRetriesJob can dispatch a patch retry, a cancel retry and one refund
 * retry per failed line for the same billable inside a single loop iteration, and
 * PrepareUsageOverageJob's retry pass adds the overage job on top. Each of them
 * mutates a different key of the same JSON column, so an unlocked
 * `read → modify → forceFill()->save()` has every one of them writing back its own
 * stale snapshot of all the other keys. Two refund jobs reading `[L1, L2]` and
 * writing `[L2]` / `[L1]` resurrect the sibling's line, the next sweep dispatches it
 * again, and `InvoiceService::createRefund()` has no per-line idempotency guard —
 * it clamps only against the parent invoice's `refunded_net`, which still has
 * headroom whenever just one of several lines was refunded. A resurrected line is
 * therefore a second payout to the customer.
 *
 * The mutator runs against a row re-read under `lockForUpdate` inside a transaction,
 * and the caller's in-memory instance is synced from the committed state afterwards
 * so code that keeps using the model does not start from a stale snapshot.
 *
 * It lives under Support rather than Jobs\Concerns because the services write these
 * same keys: `InvoiceService::createRefund()` appends its own failed lines, and it is
 * reachable both from a retry job (serialised by that job's WithoutOverlapping lock)
 * and from a plan change via ProrataExecutor, which that lock does not cover.
 */
trait MutatesBillingMeta
{
    /**
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $mutator  receives the
     *                                                                         freshly read meta and returns the meta to persist, or null to leave the
     *                                                                         row untouched
     * @param  array<string, mixed>  $attributes  further columns written in the same save
     */
    protected function mutateBillingMeta(Model $billable, callable $mutator, array $attributes = []): void
    {
        DB::transaction(function () use ($billable, $mutator, $attributes): void {
            // Scope-free like every other worker-side lookup: a queue worker has no
            // request or tenant context for an app-defined applyBillingScope() to key
            // off, so a scoped re-read would find nothing and silently skip the write.
            $locked = $billable->newQuery()
                ->withoutGlobalScope(BillingScope::class)
                ->whereKey($billable->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return;
            }

            $meta = (array) ($locked->subscription_meta ?? []);
            $next = $mutator($meta);

            if ($next === null) {
                return;
            }

            $locked->forceFill(array_merge($attributes, ['subscription_meta' => $next]))->save();

            // syncOriginal(), not just forceFill(): the caller's instance must look
            // clean afterwards, otherwise a later save() on it would write this
            // job's snapshot of every *other* column back over the row.
            $billable->forceFill(array_merge($attributes, ['subscription_meta' => $next]))->syncOriginal();
        });
    }
}
