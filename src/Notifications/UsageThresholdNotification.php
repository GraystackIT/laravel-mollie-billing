<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UsageThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly bool $overageAllowed;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $usageType,
        public readonly int $percent,
    ) {
        // Asks the same question the hard cap in WalletUsageService::debit() asks, so
        // the mail never promises that overage will be charged when the booking would
        // in fact be refused — e.g. because no overage price is configured for this
        // usage type on the plan. Frozen at construction because the answer depends on
        // plan, interval, mandate and the overage opt-out: any of them changing before
        // the queued mail renders inverts the hint the debit was actually judged by.
        $this->overageAllowed = $billable->canChargeBillingOverage($usageType);

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
        $hint = $this->overageAllowed
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
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
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
            'overage_allowed' => $this->overageAllowed,
        ];
    }
}
