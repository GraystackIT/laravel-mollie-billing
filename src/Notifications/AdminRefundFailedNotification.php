<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class AdminRefundFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly ?int $invoiceId;

    /** The number printed on the document — what an operator can actually look up. */
    public readonly ?string $invoiceNumber;

    public readonly string $reason;

    /**
     * `$invoice` is the refund target — the invoice the Mollie refund was posted
     * against. That is the one an operator needs: the credit note does not exist
     * (the refund failed before it was written), and the billable's newest invoice
     * is whatever unrelated renewal or earlier credit note happens to come last.
     * Optional only so pre-existing call sites keep working; always pass it.
     */
    public function __construct(
        public readonly Billable $billable,
        Throwable $exception,
        ?BillingInvoice $invoice = null,
    ) {
        // Both frozen at construction, and only as scalars: the queued mail must name
        // the invoice the refund was aimed at, and the Throwable itself is not kept —
        // exception traces routinely carry closures, which PHP refuses to serialize.
        $invoice ??= $this->probableRefundTarget($billable);
        $this->invoiceId = $invoice !== null ? (int) $invoice->id : null;
        $this->invoiceNumber = $invoice !== null && filled($invoice->serial_number) ? (string) $invoice->serial_number : null;
        $this->reason = $exception->getMessage();

        $this->initializeBillingQueue();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('billing::notifications.admin_refund_failed.subject', [
                'invoice' => (string) ($this->invoiceNumber ?? $this->invoiceId ?? '-'),
            ]))
            ->line(__('billing::notifications.admin_refund_failed.body', ['reason' => $this->reason]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_refund_failed',
            'customer' => $this->billable->getBillingName(),
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'exception' => $this->reason,
        ];
    }

    /**
     * Fallback for callers that hand in no invoice.
     *
     * Narrowed to what RefundInvoiceService::refund() will actually accept as a
     * target — a paid invoice carrying a Mollie payment id, never a credit note —
     * so the guess cannot name a document that could not have been refunded at all.
     * Tie-broken on the primary key because billingInvoices() orders by created_at
     * alone and a renewal plus its credit note can share the same second.
     */
    private function probableRefundTarget(Billable $billable): ?BillingInvoice
    {
        return $billable->billingInvoices()
            ->where('invoice_kind', '!=', InvoiceKind::Refund->value)
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNotNull('mollie_payment_id')
            ->orderByDesc('id')
            ->first();
    }
}
