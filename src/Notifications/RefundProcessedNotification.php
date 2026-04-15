<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundProcessedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $creditNote,
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
        $currency = ($this->creditNote->currency) ?: config('mollie-billing.currency', 'EUR');
        $amount = number_format(abs((int) $this->creditNote->amount_gross) / 100, 2, ',', '.').' '.$currency;

        return (new MailMessage())
            ->subject(__('billing::notifications.refund_processed.subject', ['amount' => $amount]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.refund_processed.body', ['amount' => $amount]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'refund_processed',
            'credit_note_id' => $this->creditNote->id,
            'amount_gross' => $this->creditNote->amount_gross,
            'currency' => $this->creditNote->currency,
        ];
    }
}
