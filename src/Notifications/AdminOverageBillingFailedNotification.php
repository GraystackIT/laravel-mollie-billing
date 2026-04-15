<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class AdminOverageBillingFailedNotification extends Notification
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
        $customer = $this->billable->getBillingName();
        $invoice = $this->billable->latestBillingInvoice();
        $currency = ($invoice?->currency) ?: config('mollie-billing.currency', 'EUR');
        $amount = $invoice
            ? number_format(((int) $invoice->amount_gross) / 100, 2, ',', '.').' '.$currency
            : 'unknown';

        return (new MailMessage())
            ->subject(__('billing::notifications.admin_overage_billing_failed.subject', ['customer' => $customer]))
            ->line(__('billing::notifications.admin_overage_billing_failed.body', [
                'customer' => $customer,
                'amount' => $amount,
            ]))
            ->line('Exception: '.$this->exception->getMessage())
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_overage_billing_failed',
            'customer' => $this->billable->getBillingName(),
            'exception' => $this->exception->getMessage(),
        ];
    }
}
