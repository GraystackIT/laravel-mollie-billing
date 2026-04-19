<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $invoice,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('mollie-billing.company_name', config('app.name'));
        $amount = number_format($this->invoice->amount_gross / 100, 2, ',', '.');
        $currency = $this->invoice->currency ?? config('mollie-billing.currency', 'EUR');

        return (new MailMessage())
            ->subject(__('billing::notifications.invoice_available.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.invoice_available.body', [
                'app' => $app,
                'amount' => $amount.' '.$currency,
            ]))
            ->action(__('billing::emails.view_invoice'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invoice_available',
            'invoice_id' => $this->invoice->id,
            'amount_gross' => $this->invoice->amount_gross,
            'currency' => $this->invoice->currency,
        ];
    }
}
