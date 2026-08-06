<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Events\SubscriptionExpired;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Notifications\SubscriptionCancelledNotification;
use GraystackIT\MollieBilling\Scopes\BillingScope;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
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
    use UsesBillingQueue;

    public int $uniqueFor = 3600;

    public int $tries = 1;

    public function __construct()
    {
        $this->initializeBillingQueue();
    }

    public function uniqueId(): string
    {
        return 'prepare-usage-overage';
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
        // Every pass paginates with chunkById, never chunk(): passes 3 and 4 filter
        // on the very column they mutate, and OFFSET pagination would skip a whole
        // page of rows each time the previous page dropped out of the result set.

        // ── Pass 1: per-billable lifecycle work ──
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

        // ── Pass 2: retry overage charges whose creation never reached Mollie ──
        // A snapshot that already carries a `payment_id` is skipped: the payment
        // exists and its outcome belongs to the webhook (paid → invoice + markers
        // cleared, failed → SingleChargeHandler dispatches the retry itself).
        // Dispatching here on the strength of the `pending` marker alone meant Pass 1's
        // own successful charges were retried seconds later in this same invocation,
        // producing a second Mollie payment for the same overage.
        $this->billables($billableClass)
            ->whereNotNull('subscription_meta')
            ->chunkById(200, function ($billables) use ($billableClass, $chargeService): void {
                foreach ($billables as $billable) {
                    $meta = $billable->getBillingSubscriptionMeta();

                    if (($meta['usage_overage_status'] ?? null) !== ChargeUsageOverageDirectly::STATUS_PENDING) {
                        continue;
                    }

                    $paymentId = (string) ($chargeService->pendingCharge($billable)['payment_id'] ?? '');

                    if ($paymentId !== '') {
                        continue;
                    }

                    RetryUsageOverageChargeJob::dispatch(
                        $billableClass,
                        $billable->getKey(),
                    );
                }
            });

        // ── Pass 3: expire ended subscriptions ──
        // Runs BEFORE the auto-cancel below so a billable cancelled by this very
        // invocation is never expired by it as well: `subscription_ends_at` has
        // second resolution, so with the reverse order `PastDue → Cancelled →
        // Expired` collapsed into one run whenever the two passes fell into the
        // same second, and `Cancelled` was never observable.
        //
        // Active and Trial are swept too, not just Cancelled: a coupon access grant
        // sets `subscription_ends_at` while leaving the status Active, so once it
        // lapses `hasAccessibleBillingSubscription()` closes the gate but nothing
        // transitioned the row — it kept its plan code (which is all FeatureAccess
        // looks at) and kept being picked up by Pass 1's wallet recharge.
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
                        $billable->forceFill([
                            'subscription_status' => SubscriptionStatus::Expired,
                            'subscription_source' => SubscriptionSource::None,
                        ])->save();

                        SubscriptionExpired::dispatch($billable);
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob: failed expiring billable', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // ── Pass 4: auto-cancel long-stuck past_due subscriptions ──
        // After `past_due_max_days` without a successful recovery payment we
        // transition the billable to `cancelled` with `subscription_ends_at = now`,
        // and the NEXT run's Pass 3 flips `cancelled → expired`. Recovery
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
        $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => BillingTime::nowUtc(),
        ])->save();

        SubscriptionCancelled::dispatch($billable, true);

        $this->notifyBillable(
            $billable,
            MollieBilling::resolveNotification(SubscriptionCancelledNotification::class, $billable),
        );

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

        // Trial-lifecycle notifications and expiry transitions live in
        // ProcessTrialLifecycleJob — this job is concerned with overage charging
        // and renewal-period bookkeeping only.

        // Case A — Mollie subscription continues, charge overages now.
        if ($isMollie && $renewsTomorrow && ! $isCancelled && $hasMandate) {
            $this->safeCharge($billable, $chargeService);

            return;
        }

        // Case B — Mollie subscription ends tomorrow.
        if ($isMollie && $endsTomorrow && $isCancelled && $hasMandate) {
            $this->safeCharge($billable, $chargeService);

            return;
        }

        // Case C — Local subscription continues: reset wallet quota.
        if ($isLocal && $renewsTomorrow && ! $isCancelled) {
            $this->resetLocalQuota($billable, $walletService, $tomorrowStart);

            return;
        }

        // Case D — Local ending tomorrow: no-op (admin-driven).
    }

    private function safeCharge(Model $billable, ChargeUsageOverageDirectly $chargeService): void
    {
        $hasOverage = false;
        foreach ($billable->wallets()->get() as $wallet) {
            if ((int) $wallet->balanceInt < 0) {
                $hasOverage = true;
                break;
            }
        }

        if (! $hasOverage) {
            return;
        }

        try {
            // The service persists the snapshot and the `pending` marker itself; on
            // success the snapshot carries the payment id, which is what keeps Pass 2
            // from charging the same overage a second time.
            $chargeService->handle($billable);
        } catch (Throwable $e) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['usage_overage_status'] = ChargeUsageOverageDirectly::STATUS_PENDING;
            $meta['usage_overage_attempts'] = (int) ($meta['usage_overage_attempts'] ?? 0);
            $billable->forceFill(['subscription_meta' => $meta])->save();

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

        DB::transaction(function () use ($billable, $walletService, $catalog, $included, $tomorrowStart): void {
            foreach ($included as $type => $quantity) {
                if ((int) $quantity > 0) {
                    if ($catalog->usageRollover((string) $type)) {
                        $walletService->credit($billable, (string) $type, (int) $quantity, 'subscription_renewal_rollover');
                    } else {
                        $walletService->resetAndCredit($billable, (string) $type, (int) $quantity, 'subscription_renewal');
                    }
                }
            }

            $billable->forceFill([
                'subscription_period_starts_at' => $tomorrowStart,
            ])->save();
        });
    }

    private function notifyBillable(Model $billable, $notification): void
    {
        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients !== []) {
            Notification::send($recipients, $notification);
        }
    }
}
