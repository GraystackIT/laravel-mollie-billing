<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer that a change they had scheduled for the end of the period was not
 * carried out, why, and that their subscription continues unchanged.
 *
 * `$reason` is a stable key (`attempts_exhausted`, `seats_in_use`, `payment_outstanding`,
 * `not_available`) that selects the sentence; unknown keys fall back to a generic one.
 *
 * `$paymentOnItsWay`: one of the attempts had already created the payment for the change
 * and Mollie would not cancel it any more. It is returned in full when it arrives — said
 * here, so that the debit does not come as a surprise.
 */
class ScheduledChangeNotAppliedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(
        public readonly Billable $billable,
        public readonly string $planCode,
        public readonly string $reason,
        public readonly bool $paymentOnItsWay = false,
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
        $plan = app(SubscriptionCatalogInterface::class)->planName($this->planCode) ?? $this->planCode;

        $reasonKey = 'billing::notifications.scheduled_change_not_applied.reasons.'.$this->reason;
        $reason = trans()->has($reasonKey)
            ? __($reasonKey)
            : __('billing::notifications.scheduled_change_not_applied.reasons.default');

        return (new MailMessage())
            ->subject(__('billing::notifications.scheduled_change_not_applied.subject', ['app' => $app]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__('billing::notifications.scheduled_change_not_applied.body', ['plan' => $plan]))
            ->line($reason)
            ->lineIf($this->paymentOnItsWay, __('billing::notifications.scheduled_change_not_applied.payment_returned'))
            ->line(__('billing::notifications.scheduled_change_not_applied.next'))
            ->action(
                __('billing::notifications.scheduled_change_not_applied.action'),
                route(BillingRoute::name('plan'), MollieBilling::resolveUrlParameters($this->billable)),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'scheduled_change_not_applied',
            'plan_code' => $this->planCode,
            'reason' => $this->reason,
            'payment_on_its_way' => $this->paymentOnItsWay,
        ];
    }
}
