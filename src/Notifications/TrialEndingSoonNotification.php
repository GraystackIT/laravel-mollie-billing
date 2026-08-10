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

    public readonly ?CarbonInterface $trialEndsAt;

    public readonly bool $hasMandate;

    public readonly int $days;

    public function __construct(public readonly Billable $billable)
    {
        // All three are frozen here rather than read in toMail(): the mail is queued,
        // so it renders against a billable reloaded minutes or hours later. A mandate
        // that arrives in between (or a trial that already converted) flips the copy
        // to the exact opposite of what the sending pass decided — "we will charge
        // your card" for someone who never added one, or vice versa. The day count is
        // frozen with them so subject and body keep describing the same moment.
        $this->trialEndsAt = $billable->getBillingTrialEndsAt();
        $this->hasMandate = $billable->hasMollieMandate();
        $this->days = $this->trialEndsAt !== null ? $this->daysUntil($this->trialEndsAt) : 0;

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

        $bodyKey = $this->hasMandate
            ? 'billing::notifications.trial_ending_soon.body_with_mandate'
            : 'billing::notifications.trial_ending_soon.body_without_mandate';

        $message = (new MailMessage())
            ->subject(__('billing::notifications.trial_ending_soon.subject', ['days' => $this->days]))
            ->greeting(__('billing::emails.greeting', ['name' => $this->billable->getBillingName()]))
            ->line(__($bodyKey, [
                'app' => $app,
                'date' => $this->trialEndsAt?->format('Y-m-d') ?? '-',
            ]));

        $action = $this->hasMandate
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
        return [
            'type' => 'trial_ending_soon',
            'days' => $this->trialEndsAt !== null ? $this->days : null,
            'trial_ends_at' => $this->trialEndsAt?->toIso8601String(),
            'has_mandate' => $this->hasMandate,
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
