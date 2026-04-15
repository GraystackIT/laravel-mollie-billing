<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TrialEndingSoonNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Billable $billable)
    {
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
        $trialEndsAt = $this->billable->getBillingTrialEndsAt() ?? Carbon::now();
        $days = max(0, (int) Carbon::now()->diffInDays($trialEndsAt, false));
        $date = $trialEndsAt->format('Y-m-d');
        $hasMandate = $this->billable->hasMollieMandate();

        $bodyKey = $hasMandate
            ? 'billing::notifications.trial_ending_soon.body_with_mandate'
            : 'billing::notifications.trial_ending_soon.body_without_mandate';

        $message = (new MailMessage())
            ->subject(__('billing::notifications.trial_ending_soon.subject', ['days' => $days]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__($bodyKey, ['app' => $app, 'date' => $date]));

        $action = $hasMandate
            ? __('billing::emails.open_portal')
            : __('billing::emails.update_payment_method');

        return $message
            ->action($action, $this->billable->billingPortalUrl())
            ->line(__('billing::emails.signature_line', ['app' => $app]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $trialEndsAt = $this->billable->getBillingTrialEndsAt();

        return [
            'type' => 'trial_ending_soon',
            'days' => $trialEndsAt ? max(0, (int) Carbon::now()->diffInDays($trialEndsAt, false)) : null,
            'trial_ends_at' => $trialEndsAt?->toIso8601String(),
            'has_mandate' => $this->billable->hasMollieMandate(),
        ];
    }
}
