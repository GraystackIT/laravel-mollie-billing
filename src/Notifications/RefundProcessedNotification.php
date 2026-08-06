<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundProcessedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $creditNote,
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
        // A credit note carries a negative gross; the copy reads "your refund of €5.00",
        // so the magnitude is what belongs in the sentence.
        $amount = BillingMoney::format(
            abs((int) $this->creditNote->amount_gross),
            $this->creditNote->currency ?: null,
        );

        return (new MailMessage())
            ->subject(__('billing::notifications.refund_processed.subject', ['amount' => $amount]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.refund_processed.body', ['amount' => $amount]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'refund_processed',
            'credit_note_id' => $this->creditNote->id,
            'amount_gross' => $this->creditNote->amount_gross,
            'currency' => $this->creditNote->currency,
        ];
    }
}
