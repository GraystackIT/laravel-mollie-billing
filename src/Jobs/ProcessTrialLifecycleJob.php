<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialExpired;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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
 *   A. Notify billables whose trial ends in exactly N days, where N is
 *      `mollie-billing.trial_ending_soon_notice_days` (default 1, i.e. tomorrow).
 *      Sends TrialEndingSoonNotification in both cases; the notification itself
 *      branches on hasMollieMandate() to pick the right wording. The actual
 *      conversion (TrialConverted event + TrialConvertedNotification) is fired
 *      later by SubscriptionPaymentHandler::paid() when Mollie's first recurring
 *      charge succeeds — not here, because at this point no charge has happened
 *      yet and no invoice exists.
 *   B. Expire billables whose trial_ends_at lies in the past while status is still
 *      Trial *and* that never captured a mandate — no payment method means no charge
 *      is coming. Flip them to PastDue and notify; RequireActiveSubscription routes
 *      them to checkout. Mandate holders are deliberately left alone: their first
 *      charge is scheduled for exactly trial_ends_at, and the webhook owns both of
 *      its outcomes (paid → Active, failed → PastDue). See trialGenuinelyLapsed().
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

        $noticeDays = max(1, (int) config('mollie-billing.trial_ending_soon_notice_days', 1));
        $windowStart = BillingTime::nowUtc()->copy()->addDays($noticeDays)->startOfDay();
        $windowEnd = BillingTime::nowUtc()->copy()->addDays($noticeDays)->endOfDay();
        $now = BillingTime::nowUtc();

        $this->runEndingSoonPass($billableClass, $windowStart, $windowEnd);
        $this->runExpiryPass($billableClass, $now);
    }

    private function runEndingSoonPass(string $billableClass, \Carbon\CarbonInterface $windowStart, \Carbon\CarbonInterface $windowEnd): void
    {
        $this->billables($billableClass)
            ->where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->chunkById(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        $this->notify($billable, MollieBilling::resolveNotification(TrialEndingSoonNotification::class, $billable));
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
        // chunkById, not chunk: the callback flips the very column the query filters
        // on, so OFFSET pagination would skip 200 rows for every page it mutated out
        // of the result set — with 500 same-day expiries, 200 stayed on Trial (and
        // hasAccessibleBillingSubscription() keeps full access for Trial).
        $this->billables($billableClass)
            ->where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now)
            ->chunkById(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        if (! $this->trialGenuinelyLapsed($billable)) {
                            continue;
                        }

                        $billable->forceFill([
                            'subscription_status' => SubscriptionStatus::PastDue,
                        ])->save();

                        event(new TrialExpired($billable));
                        $this->notify($billable, MollieBilling::resolveNotification(TrialExpiredNotification::class, $billable));
                    } catch (Throwable $e) {
                        Log::error('ProcessTrialLifecycleJob: trial expiry failed', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * Whether the trial ended without a payment method — the only case
     * TrialExpiredNotification's wording ("no payment method was added in time")
     * is true for, and the only case that warrants revoking access here.
     *
     * A mandate holder is NOT expired by this pass. CreateSubscription schedules
     * Mollie's first charge for the same date as `trial_ends_at`, so a signup
     * between 00:00 and this job's run time already has a past `trial_ends_at` on
     * charge day, and SEPA Direct Debit stays unsettled well beyond the next run.
     * Both outcomes of that charge are owned by the webhook: `paid` activates the
     * subscription, `failed` flips the billable to PastDue with the correct
     * payment-failure notification. Expiring here in between produced a false
     * dunning mail and locked the customer out via RequireActiveSubscription.
     */
    private function trialGenuinelyLapsed(Model $billable): bool
    {
        if (! $billable->hasMollieMandate()) {
            return true;
        }

        // Defensive: a recorded charge failure while still on Trial means the
        // webhook path did not (or could not) do the transition itself.
        if (! empty($billable->getBillingSubscriptionMeta()['payment_failure'] ?? null)) {
            return true;
        }

        Log::info('ProcessTrialLifecycleJob: trial past end date but a mandate exists — awaiting first charge', [
            'billable_id' => $billable->getKey(),
            'trial_ends_at' => $billable->getBillingTrialEndsAt()?->toIso8601String(),
        ]);

        return false;
    }

    /**
     * @param  class-string  $billableClass
     */
    private function billables(string $billableClass): Builder
    {
        // Scope-free: a queue worker carries no request or tenant context for an
        // app-defined applyBillingScope() to key off.
        return $billableClass::withoutGlobalScope(BillingScope::class);
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
