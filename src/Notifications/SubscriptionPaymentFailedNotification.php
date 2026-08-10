<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A recurring subscription payment was declined.
 *
 * No invoice exists for a failed payment, so amount, currency and date have to
 * be handed in by the caller — they describe the Mollie payment, and there is no
 * local row to look them up from. Without them the mail falls back to naming no
 * amount rather than reporting an unrelated document.
 */
class SubscriptionPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly CarbonInterface $failedAt;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $paymentId,
        public readonly ?int $amountGross = null,
        public readonly ?string $currency = null,
        ?CarbonInterface $failedAt = null,
    ) {
        // Frozen here, not read in toMail(): the queued mail can go out long after
        // the payment failed, and "we could not process your payment on <date>"
        // must name the failure date, not the delivery date.
        $this->failedAt = $failedAt ?? BillingTime::nowUtc();

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
        $amount = $this->amountGross !== null
            ? BillingMoney::format($this->amountGross, $this->currency)
            : (string) __('billing::notifications.amount_unknown');

        return (new MailMessage())
            ->subject(__('billing::notifications.payment_failed.subject'))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.payment_failed.body', [
                'amount' => $amount,
                'date' => $this->failedAt->format('Y-m-d'),
                'app' => $app,
            ]))
            ->action(__('billing::emails.update_payment_method'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'payment_id' => $this->paymentId,
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
            'failed_at' => $this->failedAt->toIso8601String(),
        ];
    }
}
