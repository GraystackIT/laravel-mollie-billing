<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\CheckoutAbandoned;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Requests\GetPaginatedCustomerPaymentsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use GraystackIT\MollieBilling\Support\MollieCall;

/**
 * Removes billables that were created during a checkout flow but never
 * reached an active subscription — abandoned tabs, expired Mollie sessions,
 * mandates that were captured but never converted into a paid first charge.
 *
 * Detection (hybrid):
 *   - A billable with any BillingInvoice is never touched: an invoice means money
 *     was booked, whatever the subscription columns say.
 *   - When `pending_first_payment_id` is set in subscription_meta we poll
 *     Mollie and only clean up when the payment is in a terminal failure
 *     state (failed/canceled/expired).
 *   - Without that marker, a billable that has a Mollie customer is checked
 *     against Mollie's payment list for that customer, and kept if any payment is
 *     `paid` or if Mollie cannot be reached.
 *   - Otherwise we clean up purely based on age + the absence of any
 *     accessible subscription (so freshly-started checkouts within the
 *     threshold window are never touched).
 *
 * The actual deletion is delegated to MollieBilling::cleanupOrphanedBillableUsing()
 * — apps typically use that closure to also remove tenants/users/etc. that
 * have no other relations. When no closure is registered we fall back to
 * `$billable->delete()`. The closure may also return `false` to veto cleanup
 * for billables that legitimately exist without a subscription (e.g. admins);
 * in that case no event, mandate revocation, or log entry is produced — only a
 * `cleanup_vetoed_at` marker, which takes the row out of the candidate query so
 * the veto is not re-evaluated (at one or two Mollie calls a go) every 15 minutes.
 *
 * Captured-but-orphaned Mollie mandates are revoked best-effort after the
 * cleanup closure runs (using a pre-snapshotted ID pair, since the closure
 * may have already deleted the billable row).
 */
class CleanupOrphanedBillablesJob implements ShouldQueue
{
    use MutatesBillingMeta;

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /** Marks a billable the app's cleanup closure refused to delete. */
    public const VETOED_KEY = 'cleanup_vetoed_at';

    public int $tries = 1;

    /**
     * Cap the job at 60s so a hung Mollie HTTP call (the SDK has a very generous
     * default timeout) can't outlast the queue's visibility window. Without this,
     * a single stuck `GetPaymentRequest` could keep the job running past
     * `retry_after`, the queue would re-deliver it, and the second pickup would
     * fail immediately with `MaxAttemptsExceededException` because `attempts() >= tries`.
     */
    public int $timeout = 60;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function handle(): void
    {
        if (! (bool) config('mollie-billing.cleanup.enabled', true)) {
            return;
        }

        $billableClass = config('mollie-billing.billable_model');
        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return;
        }

        $thresholdMinutes = (int) config('mollie-billing.cleanup.threshold_minutes', 60);
        if ($thresholdMinutes <= 0) {
            return;
        }

        self::candidateQuery()?->chunkById(200, function ($billables): void {
            foreach ($billables as $billable) {
                try {
                    $this->processBillable($billable);
                } catch (\Throwable $e) {
                    Log::warning('CleanupOrphanedBillablesJob: failed to process billable', [
                        'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * The age + no-subscription pre-filter. Exposed so `billing:cleanup-orphans` can
     * report and confirm a candidate count before anything is deleted; the per-row
     * money guards in processBillable() still decide the actual outcome.
     *
     * Null when the billable model is not configured.
     */
    public static function candidateQuery(): ?Builder
    {
        $billableClass = config('mollie-billing.billable_model');
        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return null;
        }

        $thresholdMinutes = (int) config('mollie-billing.cleanup.threshold_minutes', 60);
        if ($thresholdMinutes <= 0) {
            return null;
        }

        return $billableClass::withoutGlobalScope(BillingScope::class)
            ->where(function ($q): void {
                $q->whereNull('subscription_source')
                    ->orWhere('subscription_source', SubscriptionSource::None->value);
            })
            ->where(function ($q): void {
                $q->whereNull('subscription_status')
                    ->orWhere('subscription_status', SubscriptionStatus::New->value);
            })
            // Rows the app has already vetoed are gone for good — see
            // markVetoed(). Without this exclusion they stayed candidates
            // forever: a veto leaves no trace, they keep their
            // `mollie_customer_id`, and each run spends one or two synchronous
            // Mollie calls on them again. Since chunkById always restarts from
            // the lowest id and `timeout`/`tries` cut the run off after a few
            // hundred rows, a backlog of vetoed rows meant newer orphans were
            // never reached at all. Deleting the key re-admits a row.
            ->whereJsonDoesntContainKey('subscription_meta->'.self::VETOED_KEY)
            ->where('created_at', '<', BillingTime::nowUtc()->subMinutes($thresholdMinutes));
    }

    public static function candidateCount(): int
    {
        return (int) (self::candidateQuery()?->count() ?? 0);
    }

    /**
     * How many rows the app's cleanup closure has refused to delete.
     *
     * `candidateQuery()` excludes them, so a veto shrinks the candidate count exactly like a
     * deletion does — which is why the command's "removed" figure, computed as a delta,
     * reported vetoed rows as deleted. Counted separately here so the two can be told apart.
     */
    public static function vetoedCount(): int
    {
        $billableClass = config('mollie-billing.billable_model');

        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return 0;
        }

        return (int) $billableClass::withoutGlobalScope(BillingScope::class)
            ->whereJsonContainsKey('subscription_meta->'.self::VETOED_KEY)
            ->count();
    }

    private function processBillable(Billable $billable): void
    {
        if (! ($billable instanceof Model)) {
            return;
        }

        // Defense in depth — refresh and re-check, the row may have flipped
        // mid-run (paid webhook arrived between query and processing). It may
        // also have disappeared if an earlier billable in the same chunk
        // cascade-deleted this one through the app's cleanup closure — in that
        // case nothing left to do.
        try {
            $billable->refresh();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return;
        }

        if ($billable->hasAccessibleBillingSubscription()) {
            return;
        }

        if ($billable->subscription_source !== null
            && $billable->subscription_source !== SubscriptionSource::None) {
            return;
        }

        // Hard guard #1 — collected money is never deleted and its mandate never
        // revoked. The pending-first-payment poll below cannot carry this on its own:
        // the marker only tracks the latest attempt, and the webhook now clears it on
        // `paid` only once the handlers have run. A billable whose activation threw is
        // therefore left at status New / source null with a paid invoice, collected
        // money and a live mandate — which is exactly what this guard catches.
        if ($this->hasInvoices($billable)) {
            return;
        }

        $pendingPaymentId = $billable->getPendingFirstPaymentId();

        if ($pendingPaymentId !== null && ! $this->mollieReportsTerminalFailure($pendingPaymentId)) {
            return;
        }

        // Asked unconditionally, not just when there is no marker. The marker only ever
        // tracks the *latest* attempt — each checkout submit overwrites it — so a
        // terminal failure on it does not prove nothing was collected earlier. Without
        // this, a first payment that settled but whose invoice write then failed (the
        // customer retries, the marker moves to the second, expiring attempt) got the
        // billable deleted and the paying customer's live mandate revoked.
        if ($this->mollieMayHavePaid($billable)) {
            return;
        }

        // Snapshot the Mollie identifiers before running the cleanup closure —
        // the closure may delete the billable, which detaches the model from
        // the DB row and makes attribute access unreliable.
        $customerId = (string) ($billable->mollie_customer_id ?? '');
        $mandateId = (string) ($billable->mollie_mandate_id ?? '');
        $billableKey = $billable->getKey();

        // App-level veto: the consuming app may decide that a billable matching
        // the query (e.g. an admin / employee user without a subscription) is
        // not actually orphan. In that case we suppress all side-effects —
        // event, mandate revocation, log — so the row is left fully untouched.
        $cleaned = MollieBilling::runCleanupOrphanedBillable($billable);

        if (! $cleaned) {
            $this->markVetoed($billable);

            return;
        }

        $this->revokeMandate($customerId, $mandateId);

        event(new CheckoutAbandoned($billable, (string) ($pendingPaymentId ?? '')));

        Log::info('CleanupOrphanedBillablesJob: orphaned billable cleaned up', [
            'billable_id' => $billableKey,
            'payment_id' => $pendingPaymentId,
        ]);
    }

    /**
     * Record the app's veto so the candidate query can skip this row from now on.
     *
     * The only side-effect a veto is allowed to have: no event, no mandate revocation,
     * no deletion. Written outside the veto's own "leave the row untouched" contract
     * because the alternative is re-polling Mollie for it every 15 minutes forever and
     * starving the newer rows behind it.
     */
    private function markVetoed(Model $billable): void
    {
        try {
            // Key-scoped: the veto decision came after a Mollie poll, so writing back the whole
            // blob read before it would revert whatever another process committed meanwhile —
            // `pending_refund_retries` above all, whose only reader is the hourly sweep.
            $this->mutateBillingMeta($billable, fn (array $meta): array => [
                ...$meta,
                self::VETOED_KEY => BillingTime::nowUtc()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CleanupOrphanedBillablesJob: failed to record the cleanup veto', [
                'billable_id' => $billable->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Any persisted invoice means money was booked for this billable — the local,
     * authoritative record, and the exact state the reported deletion bug produced.
     */
    private function hasInvoices(Model $billable): bool
    {
        $exists = BillingInvoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->exists();

        if ($exists) {
            Log::info('CleanupOrphanedBillablesJob: billable has invoices, refusing cleanup', [
                'billable_id' => $billable->getKey(),
            ]);
        }

        return $exists;
    }

    /**
     * Whether Mollie holds a paid payment for this billable's customer.
     *
     * Only consulted when there is no pending-payment marker to poll. Returns true
     * when Mollie is unreachable as well: "unknown" must never authorise a delete,
     * and the next run (every 15 minutes) retries.
     */
    private function mollieMayHavePaid(Model $billable): bool
    {
        $customerId = (string) ($billable->mollie_customer_id ?? '');

        if ($customerId === '') {
            return false;
        }

        try {
            $payments = MollieCall::send(new GetPaginatedCustomerPaymentsRequest($customerId, limit: 50));
        } catch (\Throwable $e) {
            Log::warning('CleanupOrphanedBillablesJob: Mollie unreachable for customer, skipping cleanup', [
                'billable_id' => $billable->getKey(),
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        foreach ($payments ?? [] as $payment) {
            if ((string) ($payment->status ?? '') === 'paid') {
                Log::info('CleanupOrphanedBillablesJob: Mollie reports a paid payment, refusing cleanup', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $payment->id ?? null,
                ]);

                return true;
            }
        }

        return false;
    }

    private function mollieReportsTerminalFailure(string $paymentId): bool
    {
        try {
            $payment = MollieCall::send(new GetPaymentRequest($paymentId));
        } catch (\Throwable $e) {
            Log::warning('CleanupOrphanedBillablesJob: Mollie unreachable for payment', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $status = (string) ($payment->status ?? '');

        return in_array($status, ['failed', 'canceled', 'expired'], true);
    }

    private function revokeMandate(string $customerId, string $mandateId): void
    {
        if ($customerId === '' || $mandateId === '') {
            return;
        }

        try {
            RevokeMollieMandateJob::dispatch($customerId, $mandateId);
        } catch (\Throwable $e) {
            Log::warning('CleanupOrphanedBillablesJob: failed to dispatch mandate revocation', [
                'customer_id' => $customerId,
                'mandate_id' => $mandateId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
