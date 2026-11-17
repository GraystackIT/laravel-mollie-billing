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
use GraystackIT\MollieBilling\Support\ChargeDeferral;
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
 *      them to checkout. Mandate holders are left alone for FIRST_CHARGE_GRACE_DAYS:
 *      their first charge is scheduled for exactly trial_ends_at, and the webhook owns
 *      both of its outcomes (paid → Active, failed → PastDue). Past that window with
 *      neither outcome recorded no charge is coming either, and they take the same
 *      transition. See trialGenuinelyLapsed().
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
     * How long a mandate-holding trial may sit past `trial_ends_at` with no word from
     * Mollie before it is treated as a charge that is not coming.
     *
     * The same figure as the stuck country correction and the stuck overage charge
     * (`HARD_LIMIT_DAYS` on both sweepers), for the same reason: it has to outlast a
     * SEPA Direct Debit that is merely still travelling, and nothing is lost by waiting
     * that long — but waiting forever is a paid plan nobody pays for.
     */
    public const FIRST_CHARGE_GRACE_DAYS = 14;

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
                        if ($this->convertUnderDeferredCharge($billable)) {
                            continue;
                        }

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
     * A trial whose first charge a discount defers past the trial's end: the trial is
     * over, the subscription is not.
     *
     * A fully covering recurring code (or free plan periods) on a trial signup moves
     * Mollie's first charge N periods past the trial end. No charge, no webhook — and the
     * webhook is what turns a trial into an active subscription. So the row stayed on
     * `trial` for months after its trial had ended, its period still the one that began
     * with the signup, and with nothing turning that period over it held the prorated
     * trial quota for the whole free time.
     *
     * At the trial's end it becomes what a charged trial becomes: active, its period
     * starting at the trial end and running on that day (`BillingAnchor`, recorded so the
     * nightly pass turns the free periods over on it — `PrepareUsageOverageJob` case A0),
     * the trial quota replaced by the period's full quota. `TrialConverted` is dispatched;
     * the conversion mail (`TrialConvertedNotification`) belongs to the first charge and
     * its invoice, and there is none yet. Mollie is not touched: it already holds the
     * full price for the deferred date.
     */
    private function convertUnderDeferredCharge(Model $billable): bool
    {
        if ($billable->getBillingSubscriptionSource() !== \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie->value
            || empty($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)
            || ! $billable->hasMollieMandate()
        ) {
            return false;
        }

        $trialEndsAt = $billable->getBillingTrialEndsAt();
        $deferredUntil = ChargeDeferral::until($billable);
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');
        $interval = $billable->getBillingSubscriptionInterval();

        if ($trialEndsAt === null || $deferredUntil === null || $planCode === '' || $interval === null
            || ! $deferredUntil->copy()->startOfDay()->greaterThan(\Carbon\Carbon::instance($trialEndsAt)->utc()->startOfDay())
        ) {
            return false;
        }

        $periodStart = \Carbon\Carbon::instance($trialEndsAt)->utc();
        $support = app(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class);

        // What the trial used beyond its quota is the trial's to settle, as at a charged
        // conversion — collected before the period is reset, outside any transaction.
        $support->collectOutstandingOverage($billable);

        $this->mutateBillingMeta($billable, static function (array $meta) use ($periodStart): array {
            $meta = \GraystackIT\MollieBilling\Support\BillingAnchor::record($meta, $periodStart->day, $periodStart);
            $meta['trial_converted_at'] = $periodStart->toIso8601String();
            unset($meta['trial_quota'], $meta['next_charge_date_override']);

            return $meta;
        }, [
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_period_starts_at' => $periodStart,
            'trial_ends_at' => null,
        ]);

        $billable->refresh();
        $support->creditPeriodQuota($billable, $planCode, $interval, 'subscription_renewal');

        event(new \GraystackIT\MollieBilling\Events\TrialConverted($billable, $planCode));

        return true;
    }

    /**
     * Whether the trial ended without a payment method — the only case
     * TrialExpiredNotification's wording ("no payment method was added in time")
     * is true for, and the only case that warrants revoking access here.
     *
     * A mandate holder is NOT expired by this pass until FIRST_CHARGE_GRACE_DAYS have
     * gone by without either outcome of the first charge. CreateSubscription schedules
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

        // The upper bound on "the webhook owns both outcomes". It only owns them if it
        // arrives. Mollie calls it for payments and for nothing else, so a subscription
        // that was cancelled or suspended at Mollie, or bound to a mandate that has since
        // been revoked, produces no payment and therefore no delivery — and a `failed`
        // delivery lost past Mollie's retry window is gone for good. `Trial` grants access
        // unconditionally (hasAccessibleBillingSubscription()) and no other pass looks at
        // the row again, so without a limit that was a paid plan for free, indefinitely.
        //
        // A payment that does arrive after this is still honoured: the renewal handler
        // accepts PastDue and activates.
        $trialEndsAt = $billable->getBillingTrialEndsAt();

        // The first charge is not always due at the trial's end. A trial started with a
        // fully covering recurring coupon (or given free plan periods) has it deferred
        // to the later of trial end and the end of that discount — `CreateSubscription`
        // and `Support\ChargeDeferral`. Measured from the trial end, a customer promised
        // "14 days trial, then three months free" was declared past due on day 29,
        // months before anything was owed. The grace window starts at the date the
        // charge is really scheduled for.
        if (ChargeDeferral::until($billable) !== null) {
            return false;
        }

        $storedDeferral = $billable->getBillingSubscriptionMeta()[ChargeDeferral::META_KEY] ?? null;

        if ($trialEndsAt !== null && is_string($storedDeferral) && $storedDeferral !== '') {
            try {
                $deferredTo = \Carbon\Carbon::parse($storedDeferral)->setTimezone('UTC');

                if ($deferredTo->greaterThan($trialEndsAt)) {
                    $trialEndsAt = $deferredTo;
                }
            } catch (Throwable) {
                // an unreadable value leaves the trial end as the yardstick
            }
        }

        if ($trialEndsAt !== null
            && $trialEndsAt->lt(BillingTime::nowUtc()->subDays(self::FIRST_CHARGE_GRACE_DAYS))
        ) {
            Log::warning('ProcessTrialLifecycleJob: no first charge arrived within the grace window — expiring the trial', [
                'billable_id' => $billable->getKey(),
                'trial_ends_at' => $trialEndsAt->toIso8601String(),
                'grace_days' => self::FIRST_CHARGE_GRACE_DAYS,
            ]);

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
