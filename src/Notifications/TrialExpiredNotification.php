<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialExpiredNotification extends Notification
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
        $trialEndsAt = $this->billable->getBillingTrialEndsAt();
        $date = $trialEndsAt ? $trialEndsAt->format('Y-m-d') : '-';

        return (new MailMessage())
            ->subject(__('billing::notifications.trial_expired.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.trial_expired.body', ['date' => $date]))
            ->action(__('billing::emails.subscribe_now'), route(BillingRoute::checkout(), MollieBilling::resolveUrlParameters($this->billable)))
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_expired',
            'trial_ends_at' => $this->billable->getBillingTrialEndsAt()?->toIso8601String(),
        ];
    }
}
