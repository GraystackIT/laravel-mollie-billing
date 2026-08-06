<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly ?CarbonInterface $endsAt;

    public function __construct(public readonly Billable $billable)
    {
        // Frozen at construction: a resubscribe before the queued mail goes out
        // clears subscription_ends_at, which would drop the date from the mail.
        $this->endsAt = $billable->getBillingSubscriptionEndsAt();

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
        $app = config('mollie-billing.company_name', config('app.name'));

        return (new MailMessage())
            ->subject(__('billing::notifications.subscription_cancelled.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.subscription_cancelled.body', [
                'date' => $this->endsAt?->format('Y-m-d') ?? '-',
            ]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_cancelled',
            'subscription_ends_at' => $this->endsAt?->toIso8601String(),
        ];
    }
}
