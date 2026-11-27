<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Webhook\Concerns;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The retry for a full refund whose `InvoiceService::createRefund()` call threw.
 *
 * `createRefund()` queues a line itself when Mollie's answer is an error — the call is
 * wrapped. Everything around that call is not: the refund lock's cache store, the token
 * lookup's bookkeeping, the credit note's write after Mollie has paid out. A throw from
 * there used to end in a log line saying "queued for retry" while nothing was queued; the
 * refund then only happened if Mollie happened to call the webhook again.
 *
 * The line goes to the queue every other refund retry uses — `pending_refund_retries`,
 * picked up by `SweepPendingRetriesJob` and run by `RetryRefundLineJob` with its backoff,
 * its 7-day window and its one admin mail at the dead letter — marked
 * `refund_outcome_unknown`: the throw does not say whether Mollie holds the refund, so the
 * retry asks Mollie (by the token, sent as refund metadata) before it posts anything.
 *
 * The token is derived from the payment, not drawn at random: a full refund of an unwanted
 * payment happens once, so every attempt at it — the first, a queued retry, a re-delivery
 * that finds nothing queued — carries the same idempotency key and the same metadata.
 *
 * The using class also uses `MutatesBillingMeta`.
 */
trait QueuesThrownRefund
{
    /**
     * Set by `settle()` before it calls `refund()`: the payment was booked by an earlier run
     * of this service, which may have reached Mollie before it died. A property rather than
     * a parameter, so `refund()` keeps the signature subclasses override.
     */
    protected bool $earlierAttempt = false;

    /** The refund token of the one full refund of this invoice's payment. */
    public static function fullRefundToken(BillingInvoice $invoice): string
    {
        return 'full-'.substr(hash('sha256', 'full-refund:'.(string) $invoice->mollie_payment_id.':'.(string) $invoice->getKey()), 0, 16);
    }

    /**
     * Park the line for `RetryRefundLineJob`. True when it is queued (now or already).
     *
     * False only when the queue itself cannot be written — the database is gone — and then
     * the refund is picked up on Mollie's next webhook delivery, by `resume()`.
     */
    protected function queueThrownRefund(Billable $billable, BillingInvoice $invoice, ProrataLine $line, \Throwable $error): bool
    {
        if (! $billable instanceof Model) {
            return false;
        }

        $line->refundToken ??= self::fullRefundToken($invoice);
        $line->refundOutcomeUnknown = true;

        try {
            $this->mutateBillingMeta($billable, function (array $meta) use ($invoice, $line, $error): ?array {
                $entries = (array) ($meta['pending_refund_retries'] ?? []);

                foreach ($entries as $entry) {
                    $queued = is_array($entry) ? (array) ($entry['line'] ?? $entry) : [];

                    // createRefund() may have queued it before the throw.
                    if ((int) ($queued['parent_invoice_id'] ?? 0) === (int) $invoice->getKey()) {
                        return null;
                    }
                }

                $entries[] = [
                    'line' => $line->toArray(),
                    'error' => $error->getMessage(),
                    'first_attempt_at' => BillingTime::nowUtc()->toIso8601String(),
                ];
                $meta['pending_refund_retries'] = $entries;

                return $meta;
            });

            return true;
        } catch (\Throwable $queueError) {
            Log::error('Refund threw and could not be queued for retry — left for the next webhook delivery', [
                'billable_id' => $billable->getKey(),
                'invoice_id' => $invoice->getKey(),
                'error' => $error->getMessage(),
                'queue_error' => $queueError->getMessage(),
            ]);

            return false;
        }
    }
}
