<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialConvertedNotification extends Notification
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
        $plan = $this->billable->getCurrentBillingPlanName() ?? '-';
        $invoice = $this->billable->latestBillingInvoice();
        $currency = ($invoice?->currency) ?: config('mollie-billing.currency', 'EUR');
        $amount = $invoice
            ? number_format(((int) $invoice->amount_gross) / 100, 2, ',', '.').' '.$currency
            : '-';

        return (new MailMessage())
            ->subject(__('billing::notifications.trial_converted.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.trial_converted.body', [
                'plan' => $plan,
                'amount' => $amount,
            ]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_converted',
            'plan' => $this->billable->getCurrentBillingPlanName(),
            'plan_code' => $this->billable->getBillingSubscriptionPlanCode(),
        ];
    }
}
