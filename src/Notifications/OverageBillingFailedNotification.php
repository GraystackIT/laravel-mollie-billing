<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverageBillingFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Billable $billable)
    {
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
        $currency = ($invoice?->currency) ?: config('mollie-billing.currency', 'EUR');
        $amount = $invoice
            ? number_format(((int) $invoice->amount_gross) / 100, 2, ',', '.').' '.$currency
            : 'unknown';

        return (new MailMessage())
            ->subject(__('billing::notifications.overage_billing_failed.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.overage_billing_failed.body', ['amount' => $amount]))
            ->action(__('billing::emails.update_payment_method'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'overage_billing_failed',
        ];
    }
}
