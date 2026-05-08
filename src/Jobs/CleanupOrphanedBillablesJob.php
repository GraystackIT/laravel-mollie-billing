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
 * `$billable->delete()`.
 *
 * Captured-but-orphaned Mollie mandates are revoked best-effort before the
 * billable goes away so we don't leave permission-to-charge floating in
 * Mollie for a customer record that is about to be detached.
 */
class CleanupOrphanedBillablesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $tries = 1;

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
        // mid-run (paid webhook arrived between query and processing).
        $billable->refresh();

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

        $this->revokeMandateIfPresent($billable);

        event(new CheckoutAbandoned($billable, (string) ($pendingPaymentId ?? '')));

        MollieBilling::runCleanupOrphanedBillable($billable);

        Log::info('CleanupOrphanedBillablesJob: orphaned billable cleaned up', [
            'billable_id' => $billable->getKey(),
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

    private function revokeMandateIfPresent(Billable $billable): void
    {
        $customerId = (string) ($billable->mollie_customer_id ?? '');
        $mandateId = (string) ($billable->mollie_mandate_id ?? '');

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
