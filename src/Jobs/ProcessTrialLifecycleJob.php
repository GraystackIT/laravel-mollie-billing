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
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
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
    use MutatesBillingMeta;
    use Queueable;
    use SerializesModels;
    use UsesBillingQueue;

    public int $uniqueFor = 3600;

    /**
     * Pass A sends mail, so a retry is not free the way the sweeps' re-dispatches
     * are. `trial_ending_notified_for` makes it safe anyway (see runEndingSoonPass),
     * but there is still nothing to gain from re-running a pass that logs and
     * continues past every per-billable failure of its own accord.
     */
    public int $tries = 1;

    /**
     * Two table walks plus a notification per hit. Bounded so a slow run cannot
     * outlast the queue's visibility window and get itself re-delivered — with
     * `tries = 1` that re-delivery dies on MaxAttemptsExceededException, silently
     * dropping the rest of the day's expiries.
     */
    public int $timeout = 900;

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

    /**
     * `trial_ending_notified_for` holds the `trial_ends_at` date this billable was
     * already warned about, so the pass can only ever send one mail per trial.
     * Without it, anything that runs the pass twice against the same window — a
     * queue re-delivery after `retry_after`, a second scheduler, a manual
     * `queue:work --once`, or simply widening the notice window in config —
     * mails the customer again.
     *
     * The date, not a boolean: a billable that trials a second time has a different
     * `trial_ends_at` and must be warned again. The marker is written after the send
     * so a failure leaves the next run free to retry it.
     */
    private function runEndingSoonPass(string $billableClass, \Carbon\CarbonInterface $windowStart, \Carbon\CarbonInterface $windowEnd): void
    {
        $this->billables($billableClass)
            ->where('subscription_status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->chunkById(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        $stamp = $billable->trial_ends_at?->toDateString();

                        if ($stamp === null) {
                            continue;
                        }

                        if (($billable->getBillingSubscriptionMeta()['trial_ending_notified_for'] ?? null) === $stamp) {
                            continue;
                        }

                        $this->notify($billable, MollieBilling::resolveNotification(TrialEndingSoonNotification::class, $billable));

                        $this->mutateBillingMeta($billable, function (array $meta) use ($stamp): array {
                            $meta['trial_ending_notified_for'] = $stamp;

                            return $meta;
                        });
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

                        // `past_due_since` alongside the status flip, like every other
                        // path into PastDue (SubscriptionPaymentHandler::failed(),
                        // RetryUsageOverageChargeJob::giveUp(), ChargebackHandler).
                        //
                        // Without it, PrepareUsageOverageJob's auto-cancel pass skips the
                        // row — it keys off exactly this stamp — so a mandate-less expired
                        // trial never walked PastDue → Cancelled → Expired and never
                        // reached the plan-field wipe. `hasPlanFeature()`, `@planFeature`
                        // and the `billing.feature` middleware kept answering from the
                        // retained plan code for a customer who never paid, and because
                        // the row stays Local with a cycling nextBillingDate(), the
                        // overage job's local-quota pass handed it fresh consumable quota
                        // every month, forever.
                        $this->mutateBillingMeta($billable, fn (array $meta): array => [
                            ...$meta,
                            'past_due_since' => $meta['past_due_since']
                                ?? BillingTime::nowUtc()->toIso8601String(),
                        ], ['subscription_status' => SubscriptionStatus::PastDue]);

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
