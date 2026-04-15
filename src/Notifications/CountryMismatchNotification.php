<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CountryMismatchNotification extends Notification
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
        $app = config('mollie-billing.company_name', config('app.name'));
        $customer = $this->billable->getBillingName();

        return (new MailMessage())
            ->subject(__('billing::notifications.country_mismatch.subject', ['customer' => $customer]))
            ->line(__('billing::notifications.country_mismatch.body', [
                'customer' => $customer,
                'user' => $this->mismatch->tax_country_user ?? '-',
                'ip' => $this->mismatch->tax_country_ip ?? '-',
                'payment' => $this->mismatch->tax_country_payment ?? '-',
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
            'type' => 'country_mismatch',
            'customer' => $this->billable->getBillingName(),
            'tax_country_user' => $this->mismatch->tax_country_user ?? null,
            'tax_country_ip' => $this->mismatch->tax_country_ip ?? null,
            'tax_country_payment' => $this->mismatch->tax_country_payment ?? null,
        ];
    }
}
