<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Notifications;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialEndingSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesBillingQueue;

    public function __construct(public readonly Billable $billable)
    {
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
        $trialEndsAt = $this->billable->getBillingTrialEndsAt() ?? BillingTime::nowUtc();
        $days = $this->daysUntil($trialEndsAt);
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
            ->action($action, $this->billable->billingPortalUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $trialEndsAt = $this->billable->getBillingTrialEndsAt();

        return [
            'type' => 'trial_ending_soon',
            'days' => $trialEndsAt ? $this->daysUntil($trialEndsAt) : null,
            'trial_ends_at' => $trialEndsAt?->toIso8601String(),
            'has_mandate' => $this->billable->hasMollieMandate(),
        ];
    }

    /**
     * Whole calendar days until the trial ends, counted in UTC.
     *
     * Measured between day boundaries rather than in elapsed hours: Carbon 3
     * returns a float, and truncating it renders "ends in 0 days" for a trial
     * that ends tomorrow at 01:00 while the job runs tonight at 02:05. Day
     * boundaries also match the calendar-day window ProcessTrialLifecycleJob
     * selects on, so the number always agrees with the pass that sent the mail.
     */
    private function daysUntil(CarbonInterface $trialEndsAt): int
    {
        $days = BillingTime::nowUtc()
            ->startOfDay()
            ->diffInDays($trialEndsAt->copy()->startOfDay(), false);

        return max(0, (int) round($days));
    }
}
