<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Carbon\CarbonInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\Concerns\UsesBillingQueue;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        // ── Pass 1: per-billable lifecycle work ──
        $billableClass::query()
            ->whereIn('subscription_status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Cancelled->value,
            ])
            ->chunk(200, function ($billables) use (
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

        // ── Pass 2: retry pending overage charges ──
        $billableClass::query()
            ->whereNotNull('subscription_meta')
            ->chunk(200, function ($billables) use ($billableClass): void {
                foreach ($billables as $billable) {
                    $meta = $billable->getBillingSubscriptionMeta();
                    $status = $meta['usage_overage_status'] ?? null;

                    if ($status === 'pending') {
                        RetryUsageOverageChargeJob::dispatch(
                            $billableClass,
                            $billable->getKey(),
                        );
                    }
                }
            });

        // ── Pass 3a: auto-cancel long-stuck past_due subscriptions ──
        // After `past_due_max_days` without a successful recovery payment we
        // transition the billable to `cancelled` with `subscription_ends_at = now`.
        // The next run's Pass 3b then flips `cancelled → expired`. Recovery
        // (PastDue → Active) still works until this cut-off — Mollie retries
        // the recurring charge on its own schedule and a successful webhook
        // clears `past_due_since`. Set `past_due_max_days` to 0 to disable.
        $maxDays = (int) config('mollie-billing.past_due_max_days', 30);
        if ($maxDays > 0) {
            $cutoff = BillingTime::nowUtc()->subDays($maxDays)->toIso8601String();

            $billableClass::query()
                ->where('subscription_status', SubscriptionStatus::PastDue->value)
                ->whereNotNull('subscription_meta')
                ->chunk(200, function ($billables) use ($cutoff): void {
                    foreach ($billables as $billable) {
                        $meta = $billable->getBillingSubscriptionMeta();
                        $since = $meta['past_due_since'] ?? null;

                        if (! is_string($since) || $since === '' || $since >= $cutoff) {
                            continue;
                        }

                        try {
                            $billable->forceFill([
                                'subscription_status' => SubscriptionStatus::Cancelled,
                                'subscription_ends_at' => BillingTime::nowUtc(),
                            ])->save();

                            Log::info('PrepareUsageOverageJob: auto-cancelled past_due billable', [
                                'billable_id' => $billable->getKey(),
                                'past_due_since' => $since,
                            ]);
                        } catch (Throwable $e) {
                            Log::error('PrepareUsageOverageJob: failed auto-cancelling past_due billable', [
                                'billable_id' => $billable->getKey(),
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        // ── Pass 3b: expire ended subscriptions ──
        $billableClass::query()
            ->where('subscription_status', SubscriptionStatus::Cancelled->value)
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', BillingTime::nowUtc())
            ->chunk(200, function ($billables): void {
                foreach ($billables as $billable) {
                    try {
                        $billable->forceFill([
                            'subscription_status' => SubscriptionStatus::Expired,
                            'subscription_source' => SubscriptionSource::None,
                        ])->save();
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob: failed expiring billable', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // ── Pass 4: apply scheduled changes ──
        $billableClass::query()
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', BillingTime::nowUtc())
            ->chunk(200, function ($billables) use ($billableClass): void {
                foreach ($billables as $billable) {
                    ApplyScheduledChangesJob::dispatch($billableClass, $billable->getKey());
                }
            });
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
            $chargeService->handle($billable);

            $meta = $billable->getBillingSubscriptionMeta();
            $meta['usage_overage_status'] = 'pending';
            $billable->forceFill(['subscription_meta' => $meta])->save();
        } catch (Throwable $e) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['usage_overage_status'] = 'pending';
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
