<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class AdminRefundFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly Throwable $exception,
    ) {
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
        $app = config('mollie-billing.company_name', config('app.name'));
        $invoice = $this->billable->latestBillingInvoice();
        $invoiceId = $invoice?->id ?? '-';
        $reason = $this->exception->getMessage();

        return (new MailMessage())
            ->subject(__('billing::notifications.admin_refund_failed.subject', ['invoice' => (string) $invoiceId]))
            ->line(__('billing::notifications.admin_refund_failed.body', ['reason' => $reason]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_refund_failed',
            'customer' => $this->billable->getBillingName(),
            'invoice_id' => $this->billable->latestBillingInvoice()?->id,
            'exception' => $this->exception->getMessage(),
        ];
    }
}
