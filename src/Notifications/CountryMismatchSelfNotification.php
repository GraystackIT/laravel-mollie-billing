<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CountryMismatchSelfNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingCountryMismatch $mismatch,
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
        return (new MailMessage())
            ->subject(__('billing::notifications.country_mismatch_self.subject'))
            ->line(__('billing::notifications.country_mismatch_self.body_intro'))
            ->line(__('billing::notifications.country_mismatch_self.body_user', [
                'country' => (string) ($this->mismatch->tax_country_user ?? '-'),
            ]))
            ->line(__('billing::notifications.country_mismatch_self.body_payment', [
                'country' => (string) ($this->mismatch->tax_country_payment ?? '-'),
            ]))
            ->line(__('billing::notifications.country_mismatch_self.body_ip', [
                'country' => (string) ($this->mismatch->tax_country_ip ?? '-'),
            ]))
            ->line(__('billing::notifications.country_mismatch_self.body_consequence'))
            ->action(__('billing::notifications.country_mismatch_self.cta'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'country_mismatch_self',
            'mismatch_id' => $this->mismatch->id,
            'tax_country_user' => $this->mismatch->tax_country_user,
            'tax_country_payment' => $this->mismatch->tax_country_payment,
            'tax_country_ip' => $this->mismatch->tax_country_ip,
        ];
    }
}
