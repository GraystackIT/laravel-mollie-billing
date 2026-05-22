<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\CheckoutAbandoned;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Removes billables that were created during a checkout flow but never
 * reached an active subscription — abandoned tabs, expired Mollie sessions,
 * mandates that were captured but never converted into a paid first charge.
 *
 * Detection (hybrid):
 *   - When `pending_first_payment_id` is set in subscription_meta we poll
 *     Mollie and only clean up when the payment is in a terminal failure
 *     state (failed/canceled/expired).
 *   - Otherwise we clean up purely based on age + the absence of any
 *     accessible subscription (so freshly-started checkouts within the
 *     threshold window are never touched).
 *
 * The actual deletion is delegated to MollieBilling::cleanupOrphanedBillableUsing()
 * — apps typically use that closure to also remove tenants/users/etc. that
 * have no other relations. When no closure is registered we fall back to
 * `$billable->delete()`. The closure may also return `false` to veto cleanup
 * for billables that legitimately exist without a subscription (e.g. admins);
 * in that case no event, mandate revocation, or log entry is produced.
 *
 * Captured-but-orphaned Mollie mandates are revoked best-effort after the
 * cleanup closure runs (using a pre-snapshotted ID pair, since the closure
 * may have already deleted the billable row).
 */
class CleanupOrphanedBillablesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

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

        $cutoff = BillingTime::nowUtc()->subMinutes($thresholdMinutes);

        $query = $billableClass::query()
            ->where(function ($q): void {
                $q->whereNull('subscription_source')
                    ->orWhere('subscription_source', SubscriptionSource::None->value);
            })
            ->where(function ($q): void {
                $q->whereNull('subscription_status')
                    ->orWhere('subscription_status', SubscriptionStatus::New->value);
            })
            ->where('created_at', '<', $cutoff);

        $query->chunkById(200, function ($billables): void {
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

        $pendingPaymentId = $billable->getPendingFirstPaymentId();

        if ($pendingPaymentId !== null) {
            if (! $this->mollieReportsTerminalFailure($pendingPaymentId)) {
                return;
            }
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
            return;
        }

        $this->revokeMandate($customerId, $mandateId);

        event(new CheckoutAbandoned($billable, (string) ($pendingPaymentId ?? '')));

        Log::info('CleanupOrphanedBillablesJob: orphaned billable cleaned up', [
            'billable_id' => $billableKey,
            'payment_id' => $pendingPaymentId,
        ]);
    }

    private function mollieReportsTerminalFailure(string $paymentId): bool
    {
        try {
            $payment = Mollie::send(new GetPaymentRequest($paymentId));
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
