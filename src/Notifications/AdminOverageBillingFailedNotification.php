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
use Throwable;

class AdminOverageBillingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ReadsPendingOverage;
    use UsesBillingQueue;

    public readonly ?int $amountGross;

    public readonly ?string $currency;

    public readonly string $reason;

    public function __construct(
        public readonly Billable $billable,
        Throwable $exception,
    ) {
        [$this->amountGross, $this->currency] = $this->pendingOverageAmount($billable);

        // Only the message is kept: a queued notification is serialized, and an
        // exception trace routinely carries closures, which PHP refuses to serialize.
        $this->reason = $exception->getMessage();

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
        $customer = $this->billable->getBillingName();
        $amount = $this->amountGross !== null
            ? BillingMoney::format($this->amountGross, $this->currency)
            : (string) __('billing::notifications.amount_unknown');

        return (new MailMessage())
            ->subject(__('billing::notifications.admin_overage_billing_failed.subject', ['customer' => $customer]))
            ->line(__('billing::notifications.admin_overage_billing_failed.body', [
                'customer' => $customer,
                'amount' => $amount,
            ]))
            ->line('Exception: '.$this->reason)
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_overage_billing_failed',
            'customer' => $this->billable->getBillingName(),
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
            'exception' => $this->reason,
        ];
    }
}
