<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
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

    public readonly string $reason;

    public function __construct(
        public readonly Billable $billable,
        Throwable $exception,
    ) {
        // Both are frozen at construction: the queued mail must name the invoice that
        // was current when the refund failed, and the Throwable itself is not kept —
        // exception traces routinely carry closures, which PHP refuses to serialize.
        $invoiceId = $billable->latestBillingInvoice()?->id;
        $this->invoiceId = $invoiceId !== null ? (int) $invoiceId : null;
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
                'invoice' => (string) ($this->invoiceId ?? '-'),
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
            'exception' => $this->reason,
        ];
    }
}
