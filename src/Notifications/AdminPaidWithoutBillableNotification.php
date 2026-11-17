<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mollie reported a successful payment but the referenced billable could not
 * be resolved locally (record was deleted between checkout and webhook, or
 * the metadata pointer is stale). The money has been charged at Mollie but
 * no invoice / wallet credit / subscription transition could be applied —
 * needs manual reconciliation.
 */
class AdminPaidWithoutBillableNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly string $paymentId,
        public readonly ?string $billableType,
        public readonly ?string $billableId,
        public readonly ?int $amountCents,
        public readonly ?string $currency,
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
            ->line('If nothing is done, the payment is refunded to the customer automatically after '.\GraystackIT\MollieBilling\Jobs\RefundUnassignedPaymentsJob::GRACE_DAYS.' days. Should the billable it names exist by then, it is booked as an ordinary payment instead.')
            ->line('Context: '.json_encode($context, JSON_PRETTY_PRINT));
    }
}
