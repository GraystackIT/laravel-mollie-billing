<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Hourly poll for hung subscription_meta.country_corrections entries.
 *
 * If the country_correction Mollie webhook never arrives (Mollie outage,
 * webhook misconfig, app downtime that exhausted Mollie's retry window),
 * the pending entry sits forever — the dashboard banner stays visible and
 * the customer ends up with a credit note but no reissued invoice. This
 * job resolves the hang by polling Mollie directly:
 *
 * - status `paid` → call handleCountryCorrectionPaid() (idempotent: existing
 *   invoice for the payment short-circuits, otherwise the reissue invoice
 *   is created and the pending entry is cleared by createCorrectionInvoice).
 * - status `failed`/`canceled`/`expired` → call handleCountryCorrectionFailed()
 *   (clears the pending entry, rolls the mismatch back to Pending, re-cancels
 *   the subscription at period end, notifies).
 * - status pending/open/authorized AND entry > 24h old → treat as failure:
 *   synthesise a failed payment object and run handleCountryCorrectionFailed
 *   so the same recovery path applies — user sees the Pending banner again
 *   and can retry the resolve flow without a double refund (refund step is
 *   skipped via `refunded_net >= amount_net`).
 * - status pending/open/authorized AND entry < 24h old → keep waiting.
 */
class CleanupStalePendingCountryCorrectionJob implements ShouldQueue
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
        $billableClass = config('mollie-billing.billable_model');
        if (! is_string($billableClass) || ! class_exists($billableClass)) {
            return;
        }

        // Resolve any billable that has at least one country_corrections entry.
        // Filtering by age happens per-entry inside processBillable() because a
        // billable can hold multiple entries with different ages.
        $billables = $billableClass::query()
            ->whereNotNull('subscription_meta')
            ->get()
            ->filter(static function ($b): bool {
                $pending = $b->getBillingSubscriptionMeta()['country_corrections'] ?? [];
                return ! empty($pending);
            });

        foreach ($billables as $billable) {
            $this->processBillable($billable);
        }
    }

    private function processBillable(Billable $billable): void
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $pending = (array) ($meta['country_corrections'] ?? []);
        if ($pending === []) {
            return;
        }

        $now = BillingTime::nowUtc();

        foreach ($pending as $paymentId => $entry) {
            $paymentId = (string) $paymentId;
            if ($paymentId === '') {
                continue;
            }

            // Anything younger than 1h is left alone — Mollie webhooks normally
            // arrive within seconds. Polling sooner would just hammer the API.
            $createdAt = isset($entry['created_at'])
                ? \Carbon\Carbon::parse((string) $entry['created_at'])->setTimezone('UTC')
                : null;
            if ($createdAt !== null && $createdAt->diffInMinutes($now) < 60) {
                continue;
            }

            try {
                $payment = Mollie::send(new GetPaymentRequest($paymentId));
            } catch (\Throwable $e) {
                Log::warning('CleanupStalePendingCountryCorrectionJob: Mollie unreachable', [
                    'billable_id' => $billable->getKey(),
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $status = (string) ($payment->status ?? '');
            $metadata = (array) ($payment->metadata ?? []);
            if (is_object($payment->metadata ?? null)) {
                $metadata = json_decode(json_encode($payment->metadata), true) ?: [];
            }

            $controller = app(MollieWebhookController::class);

            if ($status === 'paid') {
                // Re-runs the same code path the webhook would have. Idempotent:
                // a duplicate (real webhook arriving later) finds the invoice
                // already persisted and no-ops.
                $controller->handleCountryCorrectionPaid($payment, $billable, $metadata);
                continue;
            }

            if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
                $controller->handleCountryCorrectionFailed($payment, $billable, $metadata);
                continue;
            }

            // Status pending/open/authorized: only escalate after the 24h hard limit.
            $ageHours = $createdAt?->diffInHours($now) ?? PHP_INT_MAX;
            if ($ageHours < 24) {
                continue;
            }

            // Treat a 24h+ hang as a failure: synthesise a payment object that
            // looks `failed` so handleCountryCorrectionFailed runs the same path
            // a real failed-webhook would — drop the pending entry, flip the
            // mismatch back to Pending, re-cancel the subscription, notify.
            $synthesized = clone $payment;
            $synthesized->status = 'failed';
            $synthesized->details = (object) [
                'failureReason' => "Country correction stuck for >24h at Mollie status '{$status}'",
            ];
            Log::warning('CleanupStalePendingCountryCorrectionJob: escalating stuck country_correction', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $paymentId,
                'mollie_status' => $status,
                'mismatch_id' => (int) ($entry['mismatch_id'] ?? 0),
            ]);
            $controller->handleCountryCorrectionFailed($synthesized, $billable, $metadata);
        }
    }
}
