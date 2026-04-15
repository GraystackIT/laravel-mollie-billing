<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Jobs;

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Notifications\TrialConvertedNotification;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
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

class PrepareUsageOverageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 1;

    public function uniqueId(): string
    {
        return 'prepare-usage-overage';
    }

    public function handle(
        ChargeUsageOverageDirectly $chargeService,
        WalletUsageService $walletService,
        ScheduleSubscriptionChange $scheduler,
    ): void {
        $billableClass = (string) config('mollie-billing.billable_model');

        if ($billableClass === '' || ! class_exists($billableClass)) {
            Log::warning('PrepareUsageOverageJob: billable model not configured');

            return;
        }

        $tomorrowStart = now()->copy()->addDay()->startOfDay();
        $tomorrowEnd = now()->copy()->addDay()->endOfDay();

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

        // ── Pass 3: expire ended subscriptions ──
        $billableClass::query()
            ->where('subscription_status', SubscriptionStatus::Cancelled->value)
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', now())
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
            ->where('scheduled_change_at', '<=', now())
            ->chunk(200, function ($billables) use ($scheduler): void {
                foreach ($billables as $billable) {
                    try {
                        $scheduler->apply($billable);
                    } catch (Throwable $e) {
                        Log::error('PrepareUsageOverageJob: scheduled change apply failed', [
                            'billable_id' => $billable->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function processBillable(
        Model $billable,
        ChargeUsageOverageDirectly $chargeService,
        WalletUsageService $walletService,
        Carbon $tomorrowStart,
        Carbon $tomorrowEnd,
    ): void {
        $nextBilling = $billable->nextBillingDate();
        $endsAt = $billable->getBillingSubscriptionEndsAt();
        $trialEnd = $billable->getBillingTrialEndsAt();

        $renewsTomorrow = $nextBilling !== null
            && $nextBilling->betweenIncluded($tomorrowStart, $tomorrowEnd);
        $endsTomorrow = $endsAt !== null
            && $endsAt->betweenIncluded($tomorrowStart, $tomorrowEnd);

        $isMollie = $billable->getBillingSubscriptionSource() === SubscriptionSource::Mollie->value;
        $isLocal = $billable->isLocalBillingSubscription();
        $hasMandate = $billable->hasMollieMandate();
        $isCancelled = $billable->getBillingSubscriptionStatus() === SubscriptionStatus::Cancelled;

        // Trial transitions
        if ($trialEnd !== null && $trialEnd->betweenIncluded($tomorrowStart, $tomorrowEnd)) {
            if ($hasMandate) {
                $this->notifyBillable($billable, new TrialConvertedNotification($billable));
                event(new TrialConverted(
                    $billable,
                    $billable->getBillingSubscriptionPlanCode() ?? '',
                ));
            } else {
                $this->notifyBillable($billable, new TrialEndingSoonNotification($billable));
            }
        }

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
        Carbon $tomorrowStart,
    ): void {
        $catalog = app(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class);
        $planCode = $billable->getBillingSubscriptionPlanCode() ?? '';

        $included = (array) (config('mollie-billing-plans.plans.'.$planCode.'.included_usages') ?? []);

        foreach ($billable->wallets()->get() as $wallet) {
            $slug = (string) $wallet->slug;
            $balance = (int) $wallet->balanceInt;

            // Bring wallet to zero (negative or positive) before refilling.
            if ($balance > 0) {
                $wallet->forceWithdraw($balance, ['type' => $slug, 'reason' => 'period_reset']);
            } elseif ($balance < 0) {
                $wallet->deposit(abs($balance), ['type' => $slug, 'reason' => 'period_reset']);
            }
        }

        // Refill from catalog.
        foreach ($included as $type => $quantity) {
            if ((int) $quantity > 0) {
                $walletService->credit($billable, (string) $type, (int) $quantity);
            }
        }

        $billable->forceFill([
            'subscription_period_starts_at' => $tomorrowStart,
        ])->save();
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
