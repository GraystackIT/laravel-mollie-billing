<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public readonly ?CarbonInterface $trialEndsAt;

    public function __construct(public readonly Billable $billable)
    {
        // Frozen at construction: subscribing from the CTA in this very mail starts a
        // new period and can move or clear trial_ends_at, and the queued mail would
        // then name a date the expired trial never ended on.
        $this->trialEndsAt = $billable->getBillingTrialEndsAt();

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
        $date = $this->trialEndsAt?->format('Y-m-d') ?? '-';

        return (new MailMessage())
            ->subject(__('billing::notifications.trial_expired.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.trial_expired.body', ['date' => $date]))
            ->action(__('billing::emails.subscribe_now'), route(BillingRoute::checkout(), MollieBilling::resolveUrlParameters($this->billable)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_expired',
            'trial_ends_at' => $this->trialEndsAt?->toIso8601String(),
        ];
    }
}
