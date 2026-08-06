<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CountryMismatchReissueFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingCountryMismatch $mismatch,
        public readonly string $reason,
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
        return (new MailMessage())
            ->subject(__('billing::notifications.country_mismatch_reissue_failed.subject', [
                'mismatch' => (string) $this->mismatch->id,
            ]))
            ->line(__('billing::notifications.country_mismatch_reissue_failed.body', [
                'customer' => $this->billable->getBillingName(),
                'reason' => $this->reason,
            ]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'country_mismatch_reissue_failed',
            'mismatch_id' => $this->mismatch->id,
            'customer' => $this->billable->getBillingName(),
            'reason' => $this->reason,
        ];
    }
}
