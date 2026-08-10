<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\Concerns\ReadsPendingOverage;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverageBillingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ReadsPendingOverage;
    use UsesBillingQueue;

    public readonly ?int $amountGross;

    public readonly ?string $currency;

    public function __construct(public readonly Billable $billable)
    {
        [$this->amountGross, $this->currency] = $this->pendingOverageAmount($billable);

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
        $amount = $this->amountGross !== null
            ? BillingMoney::format($this->amountGross, $this->currency)
            : (string) __('billing::notifications.amount_unknown');

        return (new MailMessage())
            ->subject(__('billing::notifications.overage_billing_failed.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.overage_billing_failed.body', ['amount' => $amount]))
            ->action(__('billing::emails.update_payment_method'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'overage_billing_failed',
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
        ];
    }
}
