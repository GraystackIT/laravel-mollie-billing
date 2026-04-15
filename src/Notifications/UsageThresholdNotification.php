<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UsageThresholdNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $usageType,
        public readonly int $percent,
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
        $hint = $this->billable->allowsBillingOverage()
            ? __('billing::notifications.usage_threshold.overage_allowed')
            : __('billing::notifications.usage_threshold.overage_blocked', ['type' => $this->usageType]);

        return (new MailMessage())
            ->subject(__('billing::notifications.usage_threshold.subject', [
                'type' => $this->usageType,
                'percent' => $this->percent,
            ]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.usage_threshold.body', [
                'type' => $this->usageType,
                'percent' => $this->percent,
                'overage_hint' => $hint,
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
            'type' => 'usage_threshold',
            'usage_type' => $this->usageType,
            'percent' => $this->percent,
            'overage_allowed' => $this->billable->allowsBillingOverage(),
        ];
    }
}
