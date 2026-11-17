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
 * Announces a separate charge for the part of a billing period that its regular payment
 * did not cover — with the amount and the reason, before the money moves.
 */
class UndercollectionChargedNotification extends Notification implements ShouldQueue
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

        $reasonKey = 'billing::notifications.undercollection_charged.reasons.'.$this->reason;
        $reason = trans()->has($reasonKey)
            ? __($reasonKey)
            : __('billing::notifications.undercollection_charged.reasons.default');

        return (new MailMessage())
            ->subject(__('billing::notifications.undercollection_charged.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line($reason)
            ->line(__('billing::notifications.undercollection_charged.charge', [
                'amount' => BillingMoney::format($this->amountGross, $this->currency),
            ]))
            ->line(__('billing::notifications.undercollection_charged.invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'undercollection_charged',
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
            'reason' => $this->reason,
        ];
    }
}
