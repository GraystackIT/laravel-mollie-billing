<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly sweep that turns the write-only retry markers into actual queued work.
 *
 * `pending_subscription_patch`, `pending_subscription_cancel` and
 * `pending_refund_retries` are persisted by UpdateSubscription, ProrataExecutor,
 * SubscriptionPaymentHandler, CancelSubscription and InvoiceService. Two of those —
 * UpdateSubscription::queuePatchRetry() and ProrataExecutor::safelyPatch() — dispatch
 * the patch retry themselves; the cancel, the coupon-expiry patch and every refund line
 * only get their marker written and rely entirely on this sweep. Without a reader, a
 * failed Mollie PATCH leaves Mollie charging the old amount every period, a failed
 * cancel leaves it charging a customer whose access ended, and a rejected refund line
 * never reaches the customer.
 *
 * The sweep is deliberately independent of whether those call sites dispatch: every
 * retry job is ShouldBeUnique per (billable, target), so a marker that already has a
 * chain in flight is not queued twice — which matters most for refunds, where a second
 * chain would be a second payout.
 */
class SweepPendingRetriesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /**
     * Above the worst-case runtime (`timeout` × `tries` plus the backoff) and still below
     * the hourly schedule. At exactly 3600 there was zero headroom: a slow sweep was
     * still walking the table when its lock expired and the scheduler dispatched the
     * next one on top of it.
     */
    public int $uniqueFor = 1800;

    /**
     * A second run is harmless — it only re-dispatches jobs whose own uniqueness drops
     * the duplicate — and it is what keeps a timeout kill from skipping the markers past
     * the point where the worker died.
     */
    public int $tries = 2;

    /**
     * Dispatch-only work, but across the whole billable table. Bounded so a stalled
     * query cannot outlast the queue's visibility window and get the run re-delivered
     * into an immediate MaxAttemptsExceededException.
     */
    public int $timeout = 600;

    public int $backoff = 60;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return 'sweep-pending-retries';
    }

    public function handle(): void
    {
        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            Log::warning('SweepPendingRetriesJob: billable model not configured');

            return;
        }

        $billableClass::withoutGlobalScope(BillingScope::class)
            ->whereNotNull('subscription_meta')
            ->chunkById(200, function ($billables) use ($billableClass): void {
                foreach ($billables as $billable) {
                    if (! $billable instanceof Model) {
                        continue;
                    }

                    $meta = $billable->getBillingSubscriptionMeta();

                    // Guarded per row, like every pass in PrepareUsageOverageJob. `chunkById`
                    // walks ascending ids, so one unusable marker on a low id aborted the sweep
                    // for every billable above it — and this job is the *only* reader for
                    // `pending_subscription_cancel`, the coupon-expiry PATCH and every
                    // `pending_refund_retries` line. A marker holding an array where a string
                    // belongs is enough (Laravel promotes "Array to string conversion" to an
                    // ErrorException), and `tries = 2` sends the retry straight back into it: an
                    // hourly no-op forever, while Mollie keeps charging cancelled subscriptions
                    // and rejected refunds never reach their customers.
                    try {
                        $this->sweepSubscriptionPatch($billable, $meta);
                        $this->sweepSubscriptionCancel($billableClass, $billable, $meta);
                        $this->sweepRefundLines($billableClass, $billable, $meta);
                    } catch (\Throwable $e) {
                        Log::error('SweepPendingRetriesJob: skipping a billable whose markers could not be swept', [
                            'billable_id' => $billable->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * `pending_subscription_cancel` is written by CancelSubscription when Mollie did
     * not acknowledge the cancel. Left unread, Mollie keeps charging a customer whose
     * access already ended, and the recurring webhook books those charges as normal
     * renewals — the costliest of these markers to ignore.
     *
     * @param  array<string, mixed>  $meta
     */
    private function sweepSubscriptionCancel(string $billableClass, Model $billable, array $meta): void
    {
        $marker = $meta['pending_subscription_cancel'] ?? null;

        if (! is_array($marker) || $marker === []) {
            return;
        }

        RetrySubscriptionCancelJob::dispatch(
            $billableClass,
            $billable->getKey(),
            (string) ($marker['mollie_customer_id'] ?? ''),
            (string) ($marker['mollie_subscription_id'] ?? ''),
            $this->firstAttemptAt($marker),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function sweepSubscriptionPatch(Model $billable, array $meta): void
    {
        $marker = $meta['pending_subscription_patch'] ?? null;

        if (! is_array($marker) || $marker === []) {
            return;
        }

        // Two shapes in the wild: the intent-based one (UpdateSubscription,
        // ProrataExecutor) nests PlanChangeIntent::toArray() under `intent`; the
        // coupon-expiry reset (SubscriptionPaymentHandler) is flat.
        $payload = is_array($marker['intent'] ?? null) ? $marker['intent'] : $marker;

        // The flat shape carries no billable identity, which the job needs both for
        // uniqueId() and to resolve the row. It must be the configured FQCN, not
        // getMorphClass(): under Relation::enforceMorphMap() the alias fails
        // class_exists() in the job, resolveBillable() returns null, and both the PATCH
        // and clearMarker() no-op — so the marker is never cleared, the sweep
        // re-dispatches hourly forever, and Mollie keeps charging the stale amount.
        $payload['billable_type'] ??= $billable::class;
        $payload['billable_id'] ??= $billable->getKey();

        RetrySubscriptionPatchJob::dispatch(
            $payload,
            $this->firstAttemptAt($marker),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function sweepRefundLines(string $billableClass, Model $billable, array $meta): void
    {
        $entries = $meta['pending_refund_retries'] ?? null;

        if (! is_array($entries) || $entries === []) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // InvoiceService wraps the line as `['line' => …, 'error' => …]`; tolerate
            // a bare line too, the way RetryRefundLineJob's own filter does.
            $line = is_array($entry['line'] ?? null) ? $entry['line'] : $entry;

            if ($line === []) {
                continue;
            }

            RetryRefundLineJob::dispatch(
                $billableClass,
                $billable->getKey(),
                $line,
                $this->firstAttemptAt($entry),
            );
        }
    }

    /**
     * One of the three InvoiceService append sites omits `first_attempt_at`. Falling
     * back to "now" restarts that entry's window rather than dead-lettering it
     * immediately on the first sweep.
     *
     * @param  array<string, mixed>  $marker
     */
    private function firstAttemptAt(array $marker): string
    {
        $value = $marker['first_attempt_at'] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : BillingTime::nowUtc()->toIso8601String();
    }
}
