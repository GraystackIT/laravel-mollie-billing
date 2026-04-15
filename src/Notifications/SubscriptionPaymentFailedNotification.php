<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class SubscriptionPaymentFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $paymentId,
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
        $currency = ($invoice?->currency) ?: config('mollie-billing.currency', 'EUR');
        $amount = $invoice
            ? number_format(((int) $invoice->amount_gross) / 100, 2, ',', '.').' '.$currency
            : '-';
        $date = ($invoice?->created_at ?? Carbon::now())->format('Y-m-d');

        return (new MailMessage())
            ->subject(__('billing::notifications.payment_failed.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.payment_failed.body', [
                'amount' => $amount,
                'date' => $date,
                'app' => $app,
            ]))
            ->action(__('billing::emails.update_payment_method'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'payment_id' => $this->paymentId,
        ];
    }
}
