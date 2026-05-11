<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mollie reported a successful payment but the referenced billable could not
 * be resolved locally (record was deleted between checkout and webhook, or
 * the metadata pointer is stale). The money has been charged at Mollie but
 * no invoice / wallet credit / subscription transition could be applied —
 * needs manual reconciliation.
 */
class AdminPaidWithoutBillableNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $paymentId,
        public readonly ?string $billableType,
        public readonly ?string $billableId,
        public readonly ?int $amountCents,
        public readonly ?string $currency,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $context = [
            'payment_id' => $this->paymentId,
            'billable_type' => $this->billableType,
            'billable_id' => $this->billableId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];

        return (new MailMessage)
            ->subject('Mollie paid webhook received for unresolvable billable')
            ->line('A successful Mollie payment cannot be assigned to a local billable record.')
            ->line('The payment has cleared at Mollie but no invoice was generated and no wallet was credited.')
            ->line('Manual reconciliation is required.')
            ->line('Context: '.json_encode($context, JSON_PRETTY_PRINT));
    }
}
