<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Events\TrialExpired;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Drives the trial-lifecycle state machine. Runs daily on the schedule
 * registered by MollieBillingServiceProvider.
 *
 * Two passes:
 *   A. Notify billables whose trial ends tomorrow.
 *      - With a Mollie mandate captured: send TrialConvertedNotification, dispatch TrialConverted.
 *      - Without mandate: send TrialEndingSoonNotification (call-to-action to add a payment method).
 *   B. Expire billables whose trial_ends_at lies in the past while status is still Trial.
 *      Their first paid charge never landed (no mandate, or Mollie's first charge failed),
 *      so flip them to PastDue and notify. RequireActiveSubscription routes them to checkout.
 *
 * Trial-lifecycle is intentionally separate from PrepareUsageOverageJob — different
 * concerns, easier to reason about, easier to test, easier to disable independently.
 */
class ProcessTrialLifecycleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $uniqueFor = 3600;

    public int $tries = 1;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return 'process-trial-lifecycle';
    }

    public function handle(): void
    {
        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            Log::warning('ProcessTrialLifecycleJob: billable model not configured');

            return;
        }

        $tomorrowStart = BillingTime::nowUtc()->copy()->addDay()->startOfDay();
        $tomorrowEnd = BillingTime::nowUtc()->copy()->addDay()->endOfDay();
        $now = BillingTime::nowUtc();

        $this->runEndingSoonPass($billableClass, $tomorrowStart, $tomorrowEnd);
        $this->runExpiryPass($billableClass, $now);
    }

    private function runEndingSoonPass(string $billableClass, \Carbon\CarbonInterface $tomorrowStart, \Carbon\CarbonInterface $tomorrowEnd): void
    {
        $billableClass::query()
            ->where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [$tomorrowStart, $tomorrowEnd])
            ->chunk(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        if ($billable->hasMollieMandate()) {
                            $this->notify($billable, new TrialConvertedNotification($billable));
                            event(new TrialConverted($billable, $billable->getBillingSubscriptionPlanCode() ?? ''));
                        } else {
                            $this->notify($billable, new TrialEndingSoonNotification($billable));
                        }
                    } catch (Throwable $e) {
                        Log::error('ProcessTrialLifecycleJob: trial-ending notify failed', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function runExpiryPass(string $billableClass, \Carbon\CarbonInterface $now): void
    {
        $billableClass::query()
            ->where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now)
            ->chunk(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        $billable->forceFill([
                            'subscription_status' => SubscriptionStatus::PastDue,
                        ])->save();

                        event(new TrialExpired($billable));
                        $this->notify($billable, new TrialExpiredNotification($billable));
                    } catch (Throwable $e) {
                        Log::error('ProcessTrialLifecycleJob: trial expiry failed', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function notify(Model $billable, $notification): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients !== []) {
            Notification::send($recipients, $notification);
        }
    }
}
