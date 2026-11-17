<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer that a payment was collected for something that did not take effect
 * — a change they had cancelled, a subscription that had ended — and that the amount is
 * being returned in full.
 */
class UnwantedPaymentRefundedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly int $amountGross,
        public readonly string $currency,
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
        $app = config('mollie-billing.company_name', config('app.name'));

        $reasonKey = 'billing::notifications.unwanted_payment_refunded.reasons.'.$this->reason;
        $reason = trans()->has($reasonKey)
            ? __($reasonKey)
            : __('billing::notifications.unwanted_payment_refunded.reasons.default');

        return (new MailMessage())
            ->subject(__('billing::notifications.unwanted_payment_refunded.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line($reason)
            ->line(__('billing::notifications.unwanted_payment_refunded.refund', [
                'amount' => BillingMoney::format($this->amountGross, $this->currency),
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'unwanted_payment_refunded',
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
            'reason' => $this->reason,
        ];
    }
}
