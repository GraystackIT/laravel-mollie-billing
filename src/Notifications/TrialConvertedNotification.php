<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialConvertedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly ?string $planName;

    public readonly ?int $amountGross;

    public readonly ?string $currency;

    public function __construct(public readonly Billable $billable)
    {
        // Resolved at construction, not in toMail(): the queued mail runs against a
        // freshly loaded billable that may already sit on a different plan.
        $invoice = $this->latestSubscriptionInvoice($billable);

        $this->planName = $billable->getCurrentBillingPlanName();
        $this->amountGross = $invoice !== null ? (int) $invoice->amount_gross : null;
        $this->currency = $invoice?->currency ?: null;

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
            ->subject(__('billing::notifications.trial_converted.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.trial_converted.body', [
                'plan' => $this->planName ?? '-',
                'amount' => $amount,
            ]))
            ->action(__('billing::emails.open_portal'), $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_converted',
            'plan' => $this->planName,
            'plan_code' => $this->billable->getBillingSubscriptionPlanCode(),
            'amount_gross' => $this->amountGross,
            'currency' => $this->currency,
        ];
    }

    /**
     * The subscription invoice the first recurring charge just produced.
     *
     * Filtered by kind and tie-broken on the primary key: billingInvoices() orders
     * by created_at alone, so a credit note written in the same second could
     * otherwise win — and its amount_gross is negative.
     */
    private function latestSubscriptionInvoice(Billable $billable): ?BillingInvoice
    {
        return $billable->billingInvoices()
            ->where('invoice_kind', InvoiceKind::Subscription->value)
            ->orderByDesc('id')
            ->first();
    }
}
