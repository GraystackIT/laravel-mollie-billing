<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
 *
 * Runs one at a time *per billable* on top of that (see middleware()): the sweep
 * dispatches one job per failed line, and those siblings all read-modify-write the
 * same `subscription_meta` JSON — including inside InvoiceService::createRefund(),
 * which appends its own failures without a lock. Interleaved, one sibling's write
 * resurrects a line the other had already refunded, the next sweep dispatches it
 * again, and the customer is paid out twice.
 */
class RetryRefundLineJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use MutatesBillingMeta;
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
     * Serialises every refund retry for one billable, whichever line it carries.
     *
     * uniqueId() keeps a *second chain for the same line* from starting; this keeps
     * the concurrent chains for *different lines* of the same billable from
     * interleaving their `pending_refund_retries` writes. A blocked sibling is put
     * back on the queue rather than dropped — the entry it owns must not be forgotten.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->billableClass.':'.$this->billableId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
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

        // Read the committed row, not the in-memory instance: createRefund() re-appends
        // through its own save, and a stale snapshot here would report "refunded" for a
        // line Mollie had in fact rejected — the queue would then never back off.
        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $billable->refresh();
        }

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

        if (! $billable instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $signature = $this->lineSignature($this->lineData);

        // Drop the live entry as part of the same write: it now lives in the dead
        // letter, and leaving it behind would make SweepPendingRetriesJob start a
        // fresh chain on its next run — re-notifying an admin every hour forever.
        $this->mutateBillingMeta($billable, function (array $meta) use ($signature): array {
            $meta['pending_refund_retries'] = $this->withoutSignature(
                (array) ($meta['pending_refund_retries'] ?? []),
                $signature,
            );

            $meta['pending_refund_retries_dead_letter'] = array_merge(
                (array) ($meta['pending_refund_retries_dead_letter'] ?? []),
                [$this->lineData],
            );

            return $meta;
        });

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
        if (! $billable instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $signature = $this->lineSignature($this->lineData);

        $this->mutateBillingMeta($billable, function (array $meta) use ($signature): array {
            $meta['pending_refund_retries'] = $this->withoutSignature(
                (array) ($meta['pending_refund_retries'] ?? []),
                $signature,
            );

            return $meta;
        });
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return list<mixed>
     */
    private function withoutSignature(array $entries, string $signature): array
    {
        return array_values(array_filter($entries, function ($entry) use ($signature): bool {
            $line = is_array($entry) ? ($entry['line'] ?? $entry) : $entry;

            return ! is_array($line) || $this->lineSignature($line) !== $signature;
        }));
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
