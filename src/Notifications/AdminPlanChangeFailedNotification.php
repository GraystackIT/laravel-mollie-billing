<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin notification for permanent plan change failures (Mollie PATCH or refund lines
 * that did not succeed after the hard-limit backoff).
 */
class AdminPlanChangeFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {
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
        return (new MailMessage)
            ->subject(__('billing::notifications.admin_plan_change_failed.subject'))
            ->line(__('billing::notifications.admin_plan_change_failed.reason', ['reason' => $this->reason]))
            ->line(__('billing::notifications.admin_plan_change_failed.context', [
                'context' => (string) json_encode($this->context, JSON_PRETTY_PRINT),
            ]));
    }
}
