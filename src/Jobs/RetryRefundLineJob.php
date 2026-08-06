<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Retries a failed refund line from subscription_meta.pending_refund_retries.
 *
 * Backoff: 1min, 5min, 30min, 2h, then every 2h.
 * Hard limit: 7 days, or an exhausted `tries` budget — whichever comes first.
 * Either way the line moves to `pending_refund_retries_dead_letter` and an admin
 * notification goes out.
 *
 * On success a separate refund invoice carrying only this one line is created via
 * createRefund().
 *
 * ShouldBeUnique per (billable, line): the hourly SweepPendingRetriesJob keeps
 * finding the marker for as long as a retry chain is backing off, and a refund is
 * real money — a second concurrent chain would refund the customer twice.
 */
class RetryRefundLineJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    /** Lock TTL only — the lock is released as soon as the chain ends. */
    public int $uniqueFor = 604800;

    public int $tries = 50;

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public readonly array $lineData,
        public readonly string $firstAttemptAt,
    ) {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return $this->billableClass.':'.$this->billableId.':refund:'.$this->lineSignature($this->lineData);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 7200, 7200, 7200];
    }

    public function handle(InvoiceService $invoices): void
    {
        $firstAttempt = \Carbon\Carbon::parse((string) $this->firstAttemptAt)->setTimezone('UTC');
        if ($firstAttempt->diffInDays(BillingTime::nowUtc()) >= 7) {
            $this->moveToDeadLetter();

            return;
        }

        if (! class_exists($this->billableClass)) {
            return;
        }

        /** @var \GraystackIT\MollieBilling\Contracts\Billable|null $billable */
        $billable = $this->billableClass::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)->find($this->billableId);
        if ($billable === null) {
            return;
        }

        $line = ProrataLine::fromArray($this->lineData);

        // createRefund() does NOT throw on a rejected line: it catches the Mollie
        // error, re-appends the line to pending_refund_retries and returns null.
        // Clearing the stale entry up front makes a re-append unambiguously this
        // attempt's failure — and stops removeFromPendingRetries() from deleting
        // the fresh entry a naive success path would have written off.
        $this->removeFromPendingRetries($billable);

        $invoices->createRefund($billable, [$line], 'Refund retry');

        if ($this->stillPending($billable)) {
            // Throw so the queue applies the backoff ladder; the re-appended entry
            // carries the Mollie error for the next attempt and for the dead letter.
            throw new RuntimeException(sprintf(
                'Refund line retry rejected for billable [%s]: see subscription_meta.pending_refund_retries for the Mollie error.',
                (string) $this->billableId,
            ));
        }
    }

    /**
     * Reached when `tries` runs out before the 7-day window does (the ladder
     * exhausts after ~4 days). Without this the dead-letter branch in handle()
     * would never be entered and no admin would ever learn about the line.
     */
    public function failed(?Throwable $exception): void
    {
        $this->moveToDeadLetter();
    }

    private function stillPending(\GraystackIT\MollieBilling\Contracts\Billable $billable): bool
    {
        $signature = $this->lineSignature($this->lineData);

        foreach ((array) ($billable->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []) as $entry) {
            $line = is_array($entry) ? ($entry['line'] ?? $entry) : [];

            if (is_array($line) && $this->lineSignature($line) === $signature) {
                return true;
            }
        }

        return false;
    }

    private function moveToDeadLetter(): void
    {
        if (! class_exists($this->billableClass)) {
            return;
        }
        /** @var \GraystackIT\MollieBilling\Contracts\Billable|null $billable */
        $billable = $this->billableClass::withoutGlobalScope(\GraystackIT\MollieBilling\Scopes\BillingScope::class)->find($this->billableId);
        if ($billable === null) {
            return;
        }

        // Drop the live entry as part of the same write: it now lives in the dead
        // letter, and leaving it behind would make SweepPendingRetriesJob start a
        // fresh chain on its next run — re-notifying an admin every hour forever.
        $this->removeFromPendingRetries($billable);

        $meta = $billable->getBillingSubscriptionMeta();
        $meta['pending_refund_retries_dead_letter'] = array_merge(
            (array) ($meta['pending_refund_retries_dead_letter'] ?? []),
            [$this->lineData],
        );
        $billable->forceFill(['subscription_meta' => $meta])->save();

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);
        if ($admins !== []) {
            Notification::send(
                $admins,
                MollieBilling::resolveNotification(
                    AdminPlanChangeFailedNotification::class,
                    'Refund line permanently failed (retry budget exhausted)',
                    $this->lineData,
                ),
            );
        }
    }

    private function removeFromPendingRetries(\GraystackIT\MollieBilling\Contracts\Billable $billable): void
    {
        $meta = $billable->getBillingSubscriptionMeta();
        $existing = (array) ($meta['pending_refund_retries'] ?? []);
        $signature = $this->lineSignature($this->lineData);
        $filtered = array_values(array_filter($existing, function ($entry) use ($signature) {
            $line = $entry['line'] ?? $entry;
            return $this->lineSignature($line) !== $signature;
        }));
        $meta['pending_refund_retries'] = $filtered;
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    private function lineSignature(array $line): string
    {
        $invoiceId = $line['parent_invoice_id'] ?? null;
        $lineIndex = $line['parent_line_item_index'] ?? null;

        // Refund lines by design always carry parent_invoice_id + parent_line_item_index (see ProrataLine).
        // If either is missing, that indicates manually edited or corrupted serialized meta —
        // in that case we build a guaranteed-unique signature so different broken entries don't
        // collapse, and log loudly.
        if ($invoiceId === null || $lineIndex === null) {
            Log::warning('RetryRefundLineJob: pending_refund_retries-Line ohne parent_invoice_id oder parent_line_item_index', [
                'billable_class' => $this->billableClass,
                'billable_id' => $this->billableId,
                'line' => $line,
            ]);

            return 'malformed:'.hash('sha256', serialize($line));
        }

        return $invoiceId.':'.$lineIndex.':'.($line['amount_net'] ?? 0);
    }
}
