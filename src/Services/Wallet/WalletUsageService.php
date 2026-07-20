<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use Bavix\Wallet\Models\Wallet as WalletModel;
use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\UsageLimitReached;
use GraystackIT\MollieBilling\Events\WalletCredited;
use GraystackIT\MollieBilling\Events\WalletReset;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Notifications\UsageThresholdNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class WalletUsageService
{
    public function __construct(
        private readonly SubscriptionCatalogInterface $catalog,
    ) {
    }

    /**
     * Debit (consume) a quantity from the named usage wallet. The wallet is
     * created on first use. Negative balances are permitted to represent
     * overage that will later be charged via ChargeUsageOverageDirectly.
     */
    public function debit(Billable $billable, string $type, int $quantity, ?string $reason = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($billable, $type, $quantity, $reason): void {
            $wallet = $this->resolveWallet($billable, $type);

            // Pessimistic lock for atomic balance reads.
            $wallet->newQuery()->whereKey($wallet->getKey())->lockForUpdate()->first();
            $wallet->refresh();

            $balanceBefore = (int) $wallet->balanceInt;

            $hardCap = $billable->isLocalBillingSubscription()
                || ! $billable->hasMollieMandate()
                || ! $billable->allowsBillingOverage();

            $remaining = max(0, $balanceBefore);

            if ($hardCap && $quantity > $remaining) {
                event(new UsageLimitReached($billable, $type, $remaining, $quantity));

                throw new UsageLimitExceededException(
                    $billable,
                    $type,
                    $balanceBefore,
                    $quantity,
                );
            }

            // Use forceWithdraw so the wallet may go negative for overage tracking.
            $wallet->forceWithdraw($quantity, [
                'type' => $type,
                'reason' => $reason ?? 'usage',
            ]);

            $wallet->refresh();
            $balanceAfter = (int) $wallet->balanceInt;

            // Cross-zero detection for non-hard-cap path.
            if (! $hardCap && $balanceBefore >= 0 && $balanceAfter < 0) {
                event(new UsageLimitReached(
                    $billable,
                    $type,
                    max(0, $balanceBefore),
                    $quantity,
                ));
            }

            // Threshold notification.
            $this->maybeNotifyThreshold($billable, $type, $balanceAfter);
        });
    }

    /**
     * Credit (top up) a quantity into the named usage wallet. Used to seed the
     * included quota at activation time, at each renewal and for coupon credits.
     */
    public function credit(Billable $billable, string $type, int $quantity, ?string $reason = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($billable, $type, $quantity, $reason): void {
            $wallet = $this->resolveWallet($billable, $type);
            $wallet->deposit($quantity, [
                'type' => $type,
                'reason' => $reason ?? 'credit',
            ]);
        });

        event(new WalletCredited($billable, $type, $quantity, $reason ?? 'credit'));
    }

    /**
     * Reset a wallet to zero then deposit the given quota. Used when
     * rollover for this usage type is disabled — the wallet is brought
     * to exactly the plan's included quota on each renewal.
     *
     * Purchased credits (from one-time orders / coupons) survive the
     * reset: the remaining purchased balance is computed and added on
     * top of the new plan quota.
     */
    public function resetAndCredit(Billable $billable, string $type, int $quota, string $reason = 'renewal'): void
    {
        $previousBalance = 0;

        DB::transaction(function () use ($billable, $type, $quota, &$previousBalance): void {
            $wallet = $this->resolveWallet($billable, $type);
            $wallet->newQuery()->whereKey($wallet->getKey())->lockForUpdate()->first();
            $wallet->refresh();

            $previousBalance = (int) $wallet->balanceInt;
            $purchasedRemaining = self::computePurchasedRemaining(
                self::getPurchasedBalance($wallet),
                $previousBalance,
            );

            if ($previousBalance > 0) {
                $wallet->forceWithdraw($previousBalance, ['type' => $type, 'reason' => 'period_reset']);
            } elseif ($previousBalance < 0) {
                $wallet->deposit(abs($previousBalance), ['type' => $type, 'reason' => 'period_reset']);
            }

            $newBalance = $quota + $purchasedRemaining;
            if ($newBalance > 0) {
                $wallet->deposit($newBalance, ['type' => $type, 'reason' => 'credit']);
            }

            self::setPurchasedBalance($wallet, $purchasedRemaining);
        });

        event(new WalletReset($billable, $type, $previousBalance, $quota, $reason));
    }

    // ── Usage vs. bookkeeping transactions ────────────────────────────────────
    //
    // Wallet transactions cover two very different things: real consumption
    // (recordBillingUsage) and bookkeeping — plan quota top-ups, period resets,
    // plan-change adjustments and purchased credits. Usage statistics must only
    // ever count the former, otherwise a renewal reset or a credit purchase
    // shows up as "consumption".

    /**
     * Transaction reasons that represent bookkeeping, not real consumption.
     */
    public const NON_USAGE_REASONS = [
        'credit',
        'period_reset',
        'plan_change',
        'plan_change_reset',
        'plan_change_credit',
        'plan_change_upgrade',
        'plan_change_downgrade',
        'subscription_activation',
        'subscription_renewal',
        'subscription_renewal_rollover',
        'subscription_trial_start',
        'coupon_credit',
    ];

    /**
     * Prefixes of dynamic bookkeeping reasons (e.g. `one_time_order:starter-pack`).
     */
    public const NON_USAGE_REASON_PREFIXES = [
        'one_time_order:',
    ];

    /**
     * Whether a transaction reason represents real consumption.
     */
    public static function isUsageReason(?string $reason): bool
    {
        if ($reason === null || $reason === '') {
            return true;
        }

        if (in_array($reason, self::NON_USAGE_REASONS, true)) {
            return false;
        }

        foreach (self::NON_USAGE_REASON_PREFIXES as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Constrain a wallet-transaction query to real consumption, excluding
     * purchases, plan quota credits and period/plan-change resets.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scopeRealUsage($query)
    {
        return $query->where(function ($q): void {
            $q->whereNull('meta->reason')
                ->orWhere(function ($inner): void {
                    $inner->whereNotIn('meta->reason', self::NON_USAGE_REASONS);

                    foreach (self::NON_USAGE_REASON_PREFIXES as $prefix) {
                        $inner->where('meta->reason', 'not like', $prefix.'%');
                    }
                });
        });
    }

    // ── Purchased balance tracking ────────────────────────────────────────────
    //
    // One-time order and coupon credits are tracked separately from plan quotas
    // via a `purchased_balance` key in the wallet's meta JSON. This ensures
    // purchased credits survive period resets and plan changes — plan quotas
    // are consumed first, purchased credits are only depleted once the plan
    // quota is exhausted.

    /**
     * Read the purchased-credits balance from the wallet meta.
     */
    public static function getPurchasedBalance(WalletModel $wallet): int
    {
        return (int) (((array) $wallet->meta)['purchased_balance'] ?? 0);
    }

    /**
     * Write the purchased-credits balance into the wallet meta.
     */
    public static function setPurchasedBalance(WalletModel $wallet, int $amount): void
    {
        $meta = (array) $wallet->meta;
        $meta['purchased_balance'] = max(0, $amount);
        $wallet->meta = $meta;
        $wallet->save();
    }

    /**
     * Increment the purchased-credits balance (e.g. after a one-time order or coupon credit).
     */
    public static function addPurchasedBalance(WalletModel $wallet, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        self::setPurchasedBalance($wallet, self::getPurchasedBalance($wallet) + $amount);
    }

    /**
     * Compute how many purchased credits remain after consumption this period.
     *
     * Plan credits are consumed first. Purchased credits are only consumed
     * once the plan quota is exhausted:
     *
     *   purchasedRemaining = max(0, min(purchasedBalance, currentBalance))
     *
     * - If balance >= purchased: all purchased credits survive (plan covered everything).
     * - If 0 < balance < purchased: some purchased credits were consumed.
     * - If balance <= 0: all purchased credits are consumed.
     */
    public static function computePurchasedRemaining(int $purchasedBalance, int $currentBalance): int
    {
        return max(0, min($purchasedBalance, $currentBalance));
    }

    private function resolveWallet(Billable $billable, string $type): WalletModel
    {
        $wallet = $billable->getWallet($type);

        if ($wallet === null) {
            $wallet = $billable->createWallet([
                'name' => $type,
                'slug' => $type,
            ]);
        }

        return $wallet;
    }

    private function maybeNotifyThreshold(Billable $billable, string $type, int $balanceAfter): void
    {
        if (! $billable instanceof Model) {
            return;
        }

        $included = $this->catalog->includedUsage(
            $billable->getBillingSubscriptionPlanCode() ?? '',
            $billable->getBillingSubscriptionInterval(),
            $type,
        );

        if ($included <= 0) {
            return;
        }

        $used = max(0, $included - $balanceAfter);
        $percent = (int) floor(($used / $included) * 100);
        $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);

        if ($percent < $threshold) {
            return;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $notified = $meta['usage_threshold_notified_at'][$type] ?? null;
        $periodStart = $billable->getBillingPeriodStartsAt();

        $alreadyNotified = $notified !== null
            && $periodStart !== null
            && Carbon::parse((string) $notified)->setTimezone('UTC')->greaterThanOrEqualTo($periodStart);

        if ($alreadyNotified) {
            return;
        }

        $meta['usage_threshold_notified_at'][$type] = BillingTime::nowUtc()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients !== []) {
            Notification::send(
                $recipients,
                MollieBilling::resolveNotification(UsageThresholdNotification::class, $billable, $type, $percent),
            );
        }
    }
}
