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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Wiederholt fehlgeschlagene Refund-Lines aus subscription_meta.pending_refund_retries.
 *
 * Backoff: 1min, 5min, 30min, 2h, dann jede 2h.
 * Hard-Limit: 7 Tage. Danach Admin-Notification + Eintrag in dead_letter.
 *
 * Bei Erfolg: separate Refund-Invoice mit nur dieser einen Line via createRefund().
 * Bei Mollie-409: als erfolgreich verbucht (Idempotenz).
 */
class RetryRefundLineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $tries = 50; // ~7 Tage mit Backoff

    public function __construct(
        public readonly string $billableClass,
        public readonly string|int $billableId,
        public readonly array $lineData,
        public readonly string $firstAttemptAt,
    ) {
        $this->initializeBillingQueue();
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
        // Hard-Limit 7 Tage.
        $firstAttempt = \Carbon\Carbon::parse((string) $this->firstAttemptAt)->setTimezone('UTC');
        if ($firstAttempt->diffInDays(BillingTime::nowUtc()) >= 7) {
            $this->moveToDeadLetter();
            return;
        }

        if (! class_exists($this->billableClass)) {
            return;
        }

        /** @var \GraystackIT\MollieBilling\Contracts\Billable|null $billable */
        $billable = $this->billableClass::find($this->billableId);
        if ($billable === null) {
            return;
        }

        try {
            $line = ProrataLine::fromArray($this->lineData);
            $invoices->createRefund($billable, [$line], 'Refund retry');

            // Bei Erfolg: pending_refund_retries-Eintrag löschen
            $this->removeFromPendingRetries($billable);
        } catch (Throwable $e) {
            throw $e; // Job-Retry mit Backoff
        }
    }

    private function moveToDeadLetter(): void
    {
        if (! class_exists($this->billableClass)) {
            return;
        }
        /** @var \GraystackIT\MollieBilling\Contracts\Billable|null $billable */
        $billable = $this->billableClass::find($this->billableId);
        if ($billable === null) {
            return;
        }

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
                new AdminPlanChangeFailedNotification(
                    reason: 'Refund-Line dauerhaft fehlgeschlagen (>7 Tage Retries)',
                    context: $this->lineData,
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

        // Refund-Lines haben per Design immer parent_invoice_id + parent_line_item_index (siehe ProrataLine).
        // Fehlt eines davon, deutet das auf manuell editiertes oder fehlerhaft serialisiertes Meta hin —
        // wir bauen dann eine garantiert eindeutige Signatur, damit verschiedene kaputte Einträge nicht
        // kollabieren, und loggen laut.
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
