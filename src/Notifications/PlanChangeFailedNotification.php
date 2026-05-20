<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class PlanChangeFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $paymentId,
    ) {
        $connection = config('mollie-billing.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $queue = config('mollie-billing.queue.name');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
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
            ->subject(__('billing::notifications.plan_change_failed.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.plan_change_failed.body', ['app' => $app]))
            ->action(__('billing::emails.update_payment_method'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'plan_change_failed',
            'payment_id' => $this->paymentId,
        ];
    }
}
