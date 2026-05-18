<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin notification for permanent plan change failures (Mollie PATCH or refund lines
 * that did not succeed after the hard-limit backoff).
 */
class AdminPlanChangeFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Plan-Change-Operation dauerhaft fehlgeschlagen')
            ->line('Grund: '.$this->reason)
            ->line('Kontext: '.json_encode($this->context, JSON_PRETTY_PRINT));
    }
}
