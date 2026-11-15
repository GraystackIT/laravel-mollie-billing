<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer that a purchase of extras on a gifted plan was not carried out,
 * because the free period had ended before the payment arrived, and that the amount is
 * being returned.
 */
class GrantExtrasPurchaseRefundedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly int $amountGross,
        public readonly string $currency,
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

        return (new MailMessage())
            ->subject(__('billing::notifications.grant_extras_purchase_refunded.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.grant_extras_purchase_refunded.body'))
            ->line(__('billing::notifications.grant_extras_purchase_refunded.refund', [
                'amount' => BillingMoney::format($this->amountGross, $this->currency),
            ]))
            ->line(__('billing::notifications.grant_extras_purchase_refunded.next'))
            ->action(
                __('billing::emails.subscribe_now'),
                route(BillingRoute::checkout(), MollieBilling::resolveUrlParameters($this->billable)),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'grant_extras_purchase_refunded',
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
        ];
    }
}
