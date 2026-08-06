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
 * SubscriptionPaymentHandler, CancelSubscription and InvoiceService, which log
 * "queued for retry" but enqueue nothing. Without a reader, a failed Mollie PATCH
 * leaves Mollie charging the old amount every period, a failed cancel leaves it
 * charging a customer whose access ended, and a rejected refund line never reaches
 * the customer.
 *
 * The sweep is deliberately independent of whether those call sites dispatch: both
 * retry jobs are ShouldBeUnique per (billable, target), so a marker that already has a
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

    public int $uniqueFor = 3600;

    public int $tries = 1;

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

                    $this->sweepSubscriptionPatch($billable, $meta);
                    $this->sweepSubscriptionCancel($billableClass, $billable, $meta);
                    $this->sweepRefundLines($billableClass, $billable, $meta);
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
        // uniqueId() and to resolve the row.
        $payload['billable_type'] ??= $billable->getMorphClass();
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
