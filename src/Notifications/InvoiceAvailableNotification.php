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

class InvoiceAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly BillingInvoice $invoice,
    ) {
        $this->initializeBillingQueue();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('mollie-billing.company_name', config('app.name'));

        return (new MailMessage())
            ->subject(__('billing::notifications.invoice_available.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.invoice_available.body', [
                'app' => $app,
                // An empty invoice currency falls back to the configured one inside BillingMoney.
                'amount' => BillingMoney::format((int) $this->invoice->amount_gross, $this->invoice->currency ?: null),
            ]))
            ->action(__('billing::emails.view_invoice'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invoice_available',
            'invoice_id' => $this->invoice->id,
            'amount_gross' => $this->invoice->amount_gross,
            'currency' => $this->invoice->currency,
        ];
    }
}
