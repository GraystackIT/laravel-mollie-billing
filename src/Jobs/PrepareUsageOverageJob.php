<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionExpired;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminOverageSweepFailedNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\Concerns\MutatesBillingMeta;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Services\Webhook\WebhookSupport;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class PrepareUsageOverageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use MutatesBillingMeta;
    use UsesBillingQueue;

    /**
     * Above the worst-case runtime (`timeout` × `tries` plus the backoff), so the lock
     * cannot expire while the job is still working and let the scheduler stack a second
     * pass on top of the first.
     */
    public int $uniqueFor = 10800;

    /**
     * TTL of the lock `billing:prepare-overage` takes around a manual run. Same budget as
     * `$uniqueFor`, because it protects against the same thing: `dispatchSync()` bypasses
     * ShouldBeUnique (the lock lives in PendingDispatch), so a hand-started pass used to
     * walk the table alongside the scheduled one.
     */
    public const COMMAND_LOCK_SECONDS = 10800;

    /**
     * Every pass is idempotent on a second run — the in-flight guard in safeCharge()
     * skips charges pass 2 already created, resetLocalQuota() moves
     * `subscription_period_starts_at` so `renewsTomorrow` no longer matches, and the
     * status passes filter on the very status they set. That is what makes a retry safe,
     * and a retry is what keeps a mid-run kill from silently skipping the later passes:
     * with `tries = 1` a SIGKILL during the charge pass meant nothing expired, no stuck
     * `past_due` was auto-cancelled and no scheduled change was applied for that day.
     */
    public int $tries = 3;

    /**
     * One synchronous Mollie call per billable with a negative wallet, so the run scales
     * with the customer base. Bounded for the same reason CleanupOrphanedBillablesJob is:
     * a hung HTTP call must not outlast the queue's visibility window.
     */
    public int $timeout = 1800;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return 'prepare-usage-overage';
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(
        ChargeUsageOverageDirectly $chargeService,
        WalletUsageService $walletService,
    ): void {
        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            Log::warning('PrepareUsageOverageJob: billable model not configured');

            return;
        }

        $tomorrowStart = BillingTime::nowUtc()->addDay()->startOfDay();
        $tomorrowEnd = BillingTime::nowUtc()->addDay()->endOfDay();

        // Every pass bypasses BillingScope: a queue worker has no request or tenant
        // context for an app-defined applyBillingScope() to key off, so a scoped
        // model would silently reduce the sweep to zero rows.
        //
        // Every pass paginates with chunkById, never chunk(): passes 1 and 4 filter
        // on the very column they mutate, and OFFSET pagination would skip a whole
        // page of rows each time the previous page dropped out of the result set.

        // ── Pass 1: expire ended subscriptions ──
        // Runs FIRST, before the recharge pass: a coupon access grant sets
        // `subscription_ends_at` while leaving the status Active, so once it lapses
        // `hasAccessibleBillingSubscription()` closes the gate but nothing transitioned
        // the row — it kept its plan code (which is all FeatureAccess looks at) and, with
        // the recharge running first, was handed one more full period of wallet units on
        // the very run that ended it. Which is why Active and Trial are swept here too,
        // not just Cancelled.
        //
        // Runs BEFORE the auto-cancel below as well, so a billable cancelled by this very
        // invocation is never expired by it too: `subscription_ends_at` has second
        // resolution, so with the reverse order `PastDue → Cancelled → Expired` collapsed
        // into one run whenever the two passes fell into the same second, and `Cancelled`
        // was never observable.
        $this->billables($billableClass)
            ->whereIn('subscription_status', [
                SubscriptionStatus::Cancelled->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trial->value,
            ])
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', BillingTime::nowUtc())
            ->chunkById(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        // The plan-scoped fields go with the subscription. Leaving the
                        // plan code behind kept FeatureAccess resolving its features
                        // after access had ended — and FeatureAccess is a catalog
                        // lookup, so this transition is the right place to end it.
                        // Same wipe CouponService::revokeFullGrant() already performs.
                        $meta = $billable->getBillingSubscriptionMeta();
                        unset($meta['seat_count'], $meta['active_recurring_coupon']);

                        // The gifts this subscription was made of end with it. Their
                        // redemptions stay listed with a revoke button; revoking one later
                        // must not act on whatever subscription the tenant has by then (a
                        // free plan, another gift). One transaction: an expired row whose
                        // grants were not marked would leave exactly that behind.
                        DB::transaction(function () use ($billable, $meta): void {
                            $billable->forceFill([
                                'subscription_status' => SubscriptionStatus::Expired,
                                'subscription_source' => SubscriptionSource::None,
                                'subscription_plan_code' => null,
                                'subscription_interval' => null,
                                'active_addon_codes' => [],
                                'subscription_meta' => $meta,
                            ])->save();

                            app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->endGrantsWithSubscription($billable);
                        });

                        SubscriptionExpired::dispatch($billable);
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob: failed expiring billable', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // ── Pass 2: per-billable lifecycle work ──
        $this->billables($billableClass)
            ->whereIn('subscription_status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Cancelled->value,
            ])
            ->chunkById(200, function ($billables) use (
                $chargeService,
                $walletService,
                $tomorrowStart,
                $tomorrowEnd,
            ): void {
                foreach ($billables as $billable) {
                    try {
                        $this->processBillable(
                            $billable,
                            $chargeService,
                            $walletService,
                            $tomorrowStart,
                            $tomorrowEnd,
                        );
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob failed for billable', [
                            'billable_class' => $billable::class,
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // ── Pass 3: retry overage charges whose creation never reached Mollie ──
        // A snapshot that already carries a `payment_id` is skipped: the payment
        // exists and its outcome belongs to the webhook (paid → invoice + markers
        // cleared, failed → SingleChargeHandler dispatches the retry itself).
        // Dispatching here on the strength of the `pending` marker alone meant pass 2's
        // own successful charges were retried seconds later in this same invocation,
        // producing a second Mollie payment for the same overage.
        $this->billables($billableClass)
            ->whereNotNull('subscription_meta')
            ->chunkById(200, function ($billables) use ($billableClass, $chargeService): void {
                foreach ($billables as $billable) {
                    // Guarded per row, like passes 1, 2 and 4. `chunkById()` walks ascending
                    // ids and anything thrown here ends the pass — and, escaping `handle()`,
                    // the passes after it too. So one billable whose snapshot could not be
                    // read, or one failed `dispatch()` against an unreachable queue, stopped
                    // every higher id from being retried at all, silently, for a whole day.
                    try {
                        $meta = $billable->getBillingSubscriptionMeta();

                        if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
                            continue;
                        }

                        $paymentId = (string) ($chargeService->pendingCharge($billable)['payment_id'] ?? '');

                        if ($paymentId !== '') {
                            continue;
                        }

                        // No id because the create-payment call never came back: a payment may
                        // exist at Mollie. The retry ladder is only safe inside Mollie's
                        // one-hour idempotency window, and this pass runs nightly — so a retry
                        // from here would create a second payment for the same debt.
                        // `CleanupStalePendingOverageChargeJob` owns this snapshot: it asks
                        // Mollie which of the two happened and either adopts the payment or
                        // clears the marker so a later cycle can charge again.
                        if ($chargeService->paymentMayExist($billable)) {
                            continue;
                        }

                        RetryUsageOverageChargeJob::dispatch(
                            $billableClass,
                            $billable->getKey(),
                        );
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob: failed dispatching an overage charge retry', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // ── Pass 4: auto-cancel long-stuck past_due subscriptions ──
        // After `past_due_max_days` without a successful recovery payment we
        // transition the billable to `cancelled` with `subscription_ends_at = now`,
        // and the NEXT run's pass 1 flips `cancelled → expired`. Recovery
        // (PastDue → Active) still works until this cut-off — Mollie retries
        // the recurring charge on its own schedule and a successful webhook
        // clears `past_due_since`. Set `past_due_max_days` to 0 to disable.
        $maxDays = (int) config('mollie-billing.past_due_max_days', 30);
        if ($maxDays > 0) {
            $cutoff = BillingTime::nowUtc()->subDays($maxDays)->toIso8601String();

            $this->billables($billableClass)
                ->where('subscription_status', SubscriptionStatus::PastDue->value)
                ->whereNotNull('subscription_meta')
                ->chunkById(200, function ($billables) use ($cutoff): void {
                    foreach ($billables as $billable) {
                        $meta = $billable->getBillingSubscriptionMeta();
                        $since = $meta['past_due_since'] ?? null;

                        if (! is_string($since) || $since === '' || $since >= $cutoff) {
                            continue;
                        }

                        try {
                            $this->autoCancel($billable, $since);
                        } catch (Throwable $e) {
                            Log::error('PrepareUsageOverageJob: failed auto-cancelling past_due billable', [
                                'billable_id' => $billable->getKey(),
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        // ── Pass 5: apply scheduled changes ──
        $this->billables($billableClass)
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', BillingTime::nowUtc())
            ->chunkById(200, function ($billables) use ($billableClass): void {
                foreach ($billables as $billable) {
                    ApplyScheduledChangesJob::dispatch($billableClass, $billable->getKey());
                }
            });
    }

    /**
     * @param  class-string  $billableClass
     */
    private function billables(string $billableClass): Builder
    {
        return $billableClass::withoutGlobalScope(BillingScope::class);
    }

    /**
     * Ends the subscription the same way CancelSubscription does — same status,
     * same event, same customer notification. A silent forceFill() left the
     * customer without any notice and the audit trail without a row.
     */
    private function autoCancel(Model $billable, string $pastDueSince): void
    {
        /** @var Model&Billable $billable */

        // Through CancelSubscription, not by hand. This used to force-fill the status
        // and dispatch the event, which the comment called "the same way
        // CancelSubscription does" — but only the status, the event and the
        // notification matched. The Mollie subscription was never cancelled, so it kept
        // collecting: a yearly subscriber whose overage dunning gave up was cancelled
        // locally, expired by the next pass, and then charged the full yearly price at
        // the anniversary, booking an invoice for a billable with no access at all. The
        // `pending_subscription_cancel` retry marker and the `scheduled_change` wipe
        // were missing too.
        //
        // CancelSubscription sends the customer notification itself, so this no longer
        // does. A failure to reach Mollie is recorded and retried in there, which is
        // exactly the behaviour that was missing.
        try {
            app(CancelSubscription::class)->handle(
                $billable,
                immediately: true,
                reason: 'past_due_auto_cancel',
            );
        } catch (\Throwable $e) {
            Log::error('PrepareUsageOverageJob: auto-cancel failed', [
                'billable_id' => $billable->getKey(),
                'past_due_since' => $pastDueSince,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('PrepareUsageOverageJob: auto-cancelled past_due billable', [
            'billable_id' => $billable->getKey(),
            'past_due_since' => $pastDueSince,
        ]);
    }

    private function processBillable(
        Model $billable,
        ChargeUsageOverageDirectly $chargeService,
        WalletUsageService $walletService,
        CarbonInterface $tomorrowStart,
        CarbonInterface $tomorrowEnd,
    ): void {
        $nextBilling = $billable->nextBillingDate();
        $endsAt = $billable->getBillingSubscriptionEndsAt();

        $renewsTomorrow = $nextBilling !== null
            && $nextBilling->betweenIncluded($tomorrowStart, $tomorrowEnd);
        $endsTomorrow = $endsAt !== null
            && $endsAt->betweenIncluded($tomorrowStart, $tomorrowEnd);

        $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;
        $isLocal = $billable->isLocalBillingSubscription();
        $hasMandate = $billable->hasMollieMandate();
        $isCancelled = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Cancelled;
        // Past due because a *renewal* is unpaid. A subscription that is past due only for
        // a standing overage debt keeps renewing — that renewal is what collects the debt.
        $isPastDue = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::PastDue
            && ! ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable);

        // Trial-lifecycle notifications and expiry transitions live in
        // ProcessTrialLifecycleJob — this job is concerned with overage charging
        // and renewal-period bookkeeping only.
        //
        // How this fits together with the renewal, because the two are easy to read as
        // one thing and are not:
        //
        //  - TRIGGER. Exactly one case below matches a billable on a given night, keyed on
        //    "tomorrow": `nextBillingDate()` (period anchor + one interval) or
        //    `subscription_ends_at`. Each case is one line here and one method below.
        //  - CHARGE (Mollie source). This pass only *creates* the overage payment. It never
        //    resets a wallet and never moves the period anchor — both belong to the
        //    renewal webhook (`SubscriptionPaymentHandler::paid()`), which sets the anchor
        //    to the payment's `paidAt` and refills through
        //    `WebhookSupport::creditPeriodQuota()`.
        //  - SAFETY NET. Missing the trigger night no longer loses the overage: the renewal
        //    calls `WebhookSupport::collectOutstandingOverage()` before its reset, and the
        //    reset keeps any debt that is still owed (`shouldPreserveDebt()`). So a case
        //    added here does not have to be the only thing standing between a negative
        //    balance and a reset.
        //  - RESET (Local source). There is no webhook, so this pass is the renewal: it
        //    refills the wallets and moves the anchor itself (`renewLocalPeriod()`). Any
        //    new case that advances a period without a payment follows that shape — and
        //    must move `subscription_period_starts_at`, or it matches again tomorrow.

        // Case A0 — Mollie subscription whose charge is deferred past this period: this
        // pass is its renewal, exactly as it is for a Local one.
        //
        // A discount that covers the whole recurring amount keeps Mollie from charging
        // for as long as it runs, so no renewal webhook arrives — and the webhook is what
        // moves the anchor and refills the wallets for a Mollie subscription. Without this
        // case the first period never ended: the included quota was credited once for the
        // whole coupon, and `nextBillingDate()` slid into the past, where
        // `UpdateSubscription::guardPeriodNotLapsed()` refused every seat and addon
        // change with "wait for the renewal" — a renewal that was months away.
        //
        // "Due" rather than "tomorrow", so a period that already slid is caught up on the
        // first night instead of never matching again. Trial and PastDue are not periods
        // being renewed; a cancelled subscription is running out, not turning over.
        $status = $billable->getBillingSubscriptionStatus();

        // A fully covering coupon that has been switched off. Deactivating a coupon ends
        // its discount "on the next renewal", and a deferred charge has no renewal for
        // that to happen on — so this pass ends the deferral itself: full price from the
        // next regular date. From then on the billable is an ordinary subscription and
        // falls through to the cases below. See
        // MollieSubscriptionPatcher::endDeferralOfDeactivatedCoupon().
        if ($isMollie
            && in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)
            && app(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher::class)
                ->endDeferralOfDeactivatedCoupon($billable)
        ) {
            $billable->refresh();
        }

        $deferredUntil = $isMollie ? ChargeDeferral::until($billable) : null;
        $periodIsDue = $nextBilling !== null && $nextBilling->lessThanOrEqualTo($tomorrowEnd);

        if (
            $deferredUntil !== null
            && $periodIsDue
            && $deferredUntil->greaterThan($tomorrowEnd)
            && $status === SubscriptionStatus::Active
        ) {
            $this->renewDeferredPeriod($billable, $nextBilling, $tomorrowStart, $deferredUntil);

            return;
        }

        // Case A — Mollie subscription renews tomorrow: charge the period's overage now.
        if ($isMollie && $renewsTomorrow && ! $isCancelled && $hasMandate) {
            $this->safeCharge($billable, $chargeService);

            return;
        }

        // Case B — Mollie subscription ends tomorrow: last chance to charge its overage.
        if ($isMollie && $endsTomorrow && $isCancelled && $hasMandate) {
            $this->safeCharge($billable, $chargeService);

            return;
        }

        // Case C — Local subscription renews tomorrow: this pass is its renewal.
        //
        // PastDue is excluded: a past-due local subscription is not renewing, it is
        // waiting to be cancelled. `nextBillingDate()` keeps cycling for it (the period
        // anchor is never cleared), so a mandate-less expired trial parked in PastDue
        // used to collect a fresh month of consumable quota on every anniversary —
        // indefinitely, for a customer who never paid.
        //
        // Nor does a subscription that ends on (or before) that boundary: an access grant
        // of exactly two months is two periods. Refilled here it got a third quota the
        // night before it expired — and what a rollover type holds outlives the expiry.
        $endsByBoundary = $endsAt !== null && $endsAt->lessThanOrEqualTo($tomorrowEnd);

        if ($isLocal && $renewsTomorrow && ! $isCancelled && ! $isPastDue && ! $endsByBoundary) {
            $this->renewLocalPeriod($billable, $chargeService, $walletService, $tomorrowStart);

            return;
        }

        // Case D — Local ending tomorrow: no-op (admin-driven).
    }

    /**
     * A Local subscription's period boundary: collect what can be collected, then refill.
     *
     * Local plans are hard-capped, so the only debt a Local wallet can hold is one a plan
     * change left behind — typically a downgrade to the free plan whose excess could not
     * be charged at the time. If the mandate survived, that is still collectable, at the
     * price it was recorded with; without the charge the reset below was the end of it.
     */
    private function renewLocalPeriod(
        Model $billable,
        ChargeUsageOverageDirectly $chargeService,
        WalletUsageService $walletService,
        CarbonInterface $tomorrowStart,
    ): void {
        // Only for debt that carries a recorded price. Everything else on a Local wallet
        // is not something a customer was ever quoted, and stays uncharged as documented.
        $owesDeferredOverage = $billable->hasMollieMandate()
            && $billable->wallets()->get()->contains(
                static fn ($wallet): bool => WalletUsageService::deferredOverageQuantity($wallet) > 0,
            );

        if ($owesDeferredOverage) {
            $this->safeCharge($billable, $chargeService);
        }

        $this->resetLocalQuota($billable, $walletService, $tomorrowStart);
    }

    /**
     * The period boundary of a Mollie subscription that is not being charged for it.
     *
     * Same three steps the renewal webhook performs, minus the payment: collect what the
     * ending period still owes, move the anchor, refill. The overage is collected first
     * and outside any transaction — it is a Mollie payment — and the refill keeps a debt
     * that is still owed (`WebhookSupport::shouldPreserveDebt()`).
     *
     * The anchor moves to the boundary itself, not to midnight: the cycle stays the one
     * the subscription was created on, so the date the regular charge returns to when the
     * deferral ends (`ChargeDeferral::regularNextBillingDate()`) does not drift.
     */
    private function renewDeferredPeriod(
        Model $billable,
        CarbonInterface $boundary,
        CarbonInterface $tomorrowStart,
        CarbonInterface $deferredUntil,
    ): void {
        /** @var Model&Billable $billable */
        $interval = $billable->getBillingSubscriptionInterval();
        $planCode = (string) ($billable->getBillingSubscriptionPlanCode() ?? '');

        if ($planCode === '' || $interval === null) {
            return;
        }

        // Several boundaries may have passed for a subscription deferred before this
        // case existed. The quota is per period and not owed in arrears, so it is
        // credited once, for the period now beginning.
        $periodStart = \Carbon\CarbonImmutable::instance($boundary);
        // The anchor day carries on through the periods nobody is charged for, so the
        // charge that ends the deferral finds the period on the subscription's own day.
        // Nothing recorded yet (the deferral began with the signup): the period's own day.
        $anchorDay = \GraystackIT\MollieBilling\Support\BillingAnchor::day($billable)
            ?? ($billable->getBillingPeriodStartsAt() !== null
                ? \Carbon\CarbonImmutable::instance($billable->getBillingPeriodStartsAt())->utc()->day
                : null);

        for ($i = 0; $i < 600; $i++) {
            $next = \GraystackIT\MollieBilling\Support\BillingAnchor::next($periodStart, $interval, $anchorDay);

            if ($next->greaterThan($tomorrowStart)) {
                break;
            }

            $periodStart = $next;
        }

        $support = app(WebhookSupport::class);
        $support->collectOutstandingOverage($billable);

        // Anchor first: a refill that failed half-way must not be repeated in full
        // tomorrow, which is what an unmoved anchor would cause.
        $this->mutateBillingMeta($billable, function (array $meta) use ($anchorDay, $periodStart): array {
            if ($anchorDay !== null) {
                $meta = \GraystackIT\MollieBilling\Support\BillingAnchor::record($meta, $anchorDay, $periodStart);
            }

            // An override that has passed describes a period that is over.
            $override = $meta['next_charge_date_override'] ?? null;

            if (is_string($override) && $override !== '') {
                try {
                    if (! \Carbon\Carbon::parse($override)->isFuture()) {
                        unset($meta['next_charge_date_override']);
                    }
                } catch (Throwable) {
                    unset($meta['next_charge_date_override']);
                }
            }

            return $meta;
        }, ['subscription_period_starts_at' => $periodStart]);

        $billable->refresh();
        $periodEnd = $billable->nextBillingDate();

        // A plan waiver given to end, which will not cover the charge after this period:
        // end the subscription with it. Left alone, the deferred charge would fire the day
        // after the gift ends — at the full price of a plan nobody agreed to pay for.
        $endsWithWaiver = $periodEnd !== null
            && app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)->endsSubscription($billable, $periodEnd);

        // The hand-over back to the renewal webhook. A deferral does not have to end on a
        // period boundary — a recurring coupon's ended `N × 30 + 2` days after it was
        // applied until 17 November 2026 (three monthly periods from 10 January: 12 April),
        // and markers written before then keep that date; a period extension adds days.
        // Refilling on 10 April and again when the charge settles on the 12th credited the
        // same period twice, and a rollover type kept the second quota for good.
        //
        // So a stretch that is cut short by the returning charge is not a period of its
        // own: it belongs to the one before it, and the charge's own renewal brings the
        // quota. "Short" is less than half an interval — anything longer is a period the
        // customer would otherwise spend without an allowance. The anchor has moved either
        // way, so the lapsed-period guard stays quiet in between.
        $chargeCutsPeriodShort = ! $endsWithWaiver
            && $periodEnd !== null
            && $deferredUntil->lessThan($this->midpoint($periodStart, $periodEnd));

        if (! $chargeCutsPeriodShort) {
            $support->creditPeriodQuota($billable, $planCode, $interval, 'subscription_renewal');
        }

        if ($endsWithWaiver) {
            $billable->refresh();

            try {
                app(CancelSubscription::class)->handle($billable, false, 'grant_expired');
            } catch (Throwable $e) {
                Log::error('PrepareUsageOverageJob: could not end the subscription with its plan waiver', [
                    'billable_id' => $billable->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function midpoint(CarbonInterface $from, CarbonInterface $to): CarbonInterface
    {
        return \Carbon\CarbonImmutable::instance($from)
            ->addSeconds(intdiv((int) abs($to->getTimestamp() - $from->getTimestamp()), 2));
    }

    private function safeCharge(Model $billable, ChargeUsageOverageDirectly $chargeService): void
    {
        // An open charge already covers these balances. Creating a second one would
        // double-collect, because the wallets stay negative until the first payment
        // settles — with SEPA that is days, not seconds.
        //
        // Gated on the status marker, not merely on the snapshot's presence:
        // pendingChargeIsSettled() reports "settled" only once an invoice exists, so a
        // charge that failed for good would otherwise look permanently in flight and
        // silence every future overage for this billable. RetryUsageOverageChargeJob
        // leaves the snapshot behind when it gives up, precisely so the amount stays
        // auditable.
        //
        // Re-read before deciding. Pass 2 hydrates a 200-row chunk and then makes one
        // synchronous Mollie call per row, so by the time a later row is reached its attributes
        // are up to 199 round trips old — and anything that claimed the slot in that window
        // (RetryUsageOverageChargeJob, a plan change's handleExplicit(), a second pass) is
        // invisible. The result was a second recurring payment against the same mandate for the
        // same debt: two invoices, the wallet credited twice, and the first payment orphaned in
        // the snapshot so a lost webhook for it is never reconciled.
        $billable->refresh();

        $meta = $billable->getBillingSubscriptionMeta();
        $inFlight = ($meta['usage_overage_status'] ?? null) === ChargeUsageOverageDirectly::STATUS_PENDING;

        if ($inFlight && ! $chargeService->pendingChargeIsSettled($billable)) {
            Log::info('PrepareUsageOverageJob: overage charge already in flight, skipping', [
                'billable_id' => $billable->getKey(),
                'payment_id' => $chargeService->pendingCharge($billable)['payment_id'] ?? null,
            ]);

            return;
        }

        $hasOverage = false;
        foreach ($billable->wallets()->get() as $wallet) {
            if ((int) $wallet->balanceInt < 0) {
                $hasOverage = true;
                break;
            }
        }

        // A debt standing from an earlier cycle is not on a wallet. Whether it is due is
        // `handle()`'s decision; whether there is anything to ask about is this one.
        if (! $hasOverage && $chargeService->deferredDebtLines($billable) === []) {
            return;
        }

        try {
            // The service persists the snapshot and the `pending` marker itself; on
            // success the snapshot carries the payment id, which is what keeps pass 3
            // from charging the same overage a second time.
            $chargeService->handle($billable);
        } catch (Throwable $e) {
            // Through the locked mutator, and touching only the one key. This wrote the
            // whole `subscription_meta` blob from a model hydrated when its 200-row chunk
            // was fetched — and pass 2 then works through up to 199 other billables, each
            // with a synchronous Mollie call, before reaching this one. A renewal webhook
            // that committed `seat_count` in between was reverted, and a cleared dunning
            // episode resurrected, which is the exact race
            // SubscriptionPaymentHandler documents as fixed on its side.
            //
            // The `usage_overage_attempts` line that used to sit here assigned the key to
            // its own value — it never counted anything, while a test comment described it
            // as recording a fresh attempt.
            $this->mutateBillingMeta($billable, fn (array $meta): array => [
                ...$meta,
                'usage_overage_status' => ChargeUsageOverageDirectly::STATUS_PENDING,
            ]);

            Log::warning('PrepareUsageOverageJob: overage charge failed (will retry)', [
                'billable_id' => $billable->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function resetLocalQuota(
        Model $billable,
        WalletUsageService $walletService,
        CarbonInterface $tomorrowStart,
    ): void {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';
        $interval = $billable->getBillingSubscriptionInterval();

        $included = $catalog->includedUsages($planCode, $interval);
        $support = app(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class);

        // The day the period keeps through the short months, as for a Mollie renewal: a
        // free plan started on the 31st turns over on 28 February and on 31 March again,
        // not on the 28th for good (BillingAnchor).
        $anchorDay = \GraystackIT\MollieBilling\Support\BillingAnchor::forCycle(
            $billable,
            $billable->getBillingPeriodStartsAt(),
            $tomorrowStart,
            (string) ($interval ?? 'monthly'),
        );

        DB::transaction(function () use ($billable, $walletService, $catalog, $included, $tomorrowStart, $support, $anchorDay): void {
            foreach ($included as $type => $quantity) {
                if ((int) $quantity > 0) {
                    if ($catalog->usageRollover((string) $type)) {
                        // Through creditRollover(), like the webhook renewal: a bare credit()
                        // left `purchased_balance` untouched, and since a rollover type never
                        // zeroes its wallet nothing else ever brought it down — so each new
                        // period's plan quota was re-attributed to "purchased" and survived
                        // every later reset.
                        $walletService->creditRollover($billable, (string) $type, (int) $quantity);
                    } else {
                        // Same rule as the webhook renewal: a debt a charge is collecting,
                        // or one that still can be collected, is not zeroed here.
                        $walletService->resetAndCredit(
                            $billable,
                            (string) $type,
                            (int) $quantity,
                            'subscription_renewal',
                            preserveDebt: $support->shouldPreserveDebt($billable, (string) $type),
                        );
                    }
                }
            }

            $billable->forceFill([
                'subscription_period_starts_at' => $tomorrowStart,
                'subscription_meta' => \GraystackIT\MollieBilling\Support\BillingAnchor::record(
                    $billable->getBillingSubscriptionMeta(),
                    $anchorDay,
                    $tomorrowStart,
                ),
            ])->save();
        });
    }

    /**
     * A pass that exhausts its attempts must not end as a `failed_jobs` row and nothing else.
     *
     * This is the job that collects every metered overage there is. Every sibling money job
     * notifies an operator when it gives up; this one was silent, so a pass failing three
     * nights in a row looked exactly like a night with nothing to bill.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('PrepareUsageOverageJob: the overage pass failed and will not be retried', [
            'error' => $exception?->getMessage(),
        ]);

        $admins = MollieBilling::notifyAdmin();
        $admins = is_array($admins) ? $admins : iterator_to_array($admins);

        if ($admins === []) {
            return;
        }

        Notification::send($admins, new AdminOverageSweepFailedNotification(
            $exception?->getMessage() ?? 'The pass failed without reaching its end.',
        ));
    }
}
