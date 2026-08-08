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
use Illuminate\Support\Facades\Log;
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

        $thresholdPercent = null;

        DB::transaction(function () use ($billable, $type, $quantity, $reason, &$thresholdPercent): void {
            // Billable row first, wallet second — the same acquisition order the
            // subscription-change path uses (UpdateSubscription locks the billable,
            // then WalletPlanChangeAdjuster mutates the wallets), so the two can
            // never deadlock against each other. The lock is exclusive because
            // maybeNotifyThreshold() writes `subscription_meta` further down; a
            // shared lock would have to be upgraded and two concurrent debits could
            // deadlock on that upgrade.
            //
            // The refresh matters for the overage policy below: plan, interval,
            // mandate and the opt-in flag are read from the committed row, not from
            // a caller model that may have been loaded before a plan change.
            if ($billable instanceof Model) {
                $billable->newQuery()
                    ->whereKey($billable->getKey())
                    ->lockForUpdate()
                    ->first();
                $billable->refresh();
            }

            $wallet = $this->resolveWallet($billable, $type);

            // Pessimistic lock for atomic balance reads.
            $wallet->newQuery()->whereKey($wallet->getKey())->lockForUpdate()->first();
            $wallet->refresh();

            $balanceBefore = (int) $wallet->balanceInt;

            // Same rule the `hasBillingQuotaLeft()` gate answers with, so a booking
            // the app was told it may make cannot throw here — and a quota whose
            // overage price is missing (or explicitly `false`) is capped instead of
            // running up a negative balance that nothing can collect.
            $hardCap = ! $billable->canChargeBillingOverage($type);

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

            // Threshold marker only — the mail itself is sent after the commit.
            $thresholdPercent = $this->markThresholdNotified($billable, $type, $balanceAfter);
        });

        // Deliberately outside the transaction: UsageThresholdNotification is not
        // queued, so a mail-transport failure used to propagate out of debit()'s
        // transaction and roll the forceWithdraw back — recorded usage was lost
        // because an email failed, and since the marker rolled back too, every
        // subsequent debit above the threshold retried the same failing send.
        if ($thresholdPercent !== null) {
            $this->sendThresholdNotification($billable, $type, $thresholdPercent);
        }
    }

    /**
     * Credit (top up) a quantity into the named usage wallet. Used to seed the
     * included quota at activation time, at each renewal and for coupon credits.
     */
    /**
     * Credit a rollover renewal's quota, clamping the purchased balance first.
     *
     * A rollover type never zeroes its wallet, so nothing else ever brings
     * `purchased_balance` down — and it is purchased units that survive resets and plan
     * changes. Left unclamped, a stale value re-attributes each new period's plan quota to
     * "purchased": a wallet whose 500 bought units were fully consumed still reported 500
     * purchased, so the next 100-unit renewal read as 100 purchased (and 100/100 of the plan
     * quota consumed although nothing was), a later upgrade computed its target balance 500
     * units too high, and every subsequent non-rollover reset re-credited them forever.
     *
     * The webhook renewal has always clamped; `PrepareUsageOverageJob::resetLocalQuota()` —
     * the Local path — did not, which is how the two came apart. Both call this now, and
     * `SyncPurchasedBalanceCommand`'s history replay treats the same reason as a clamp point.
     */
    public function creditRollover(Billable $billable, string $type, int $quantity, ?string $reason = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $wallet = $billable->getWallet($type);

        if ($wallet !== null) {
            self::setPurchasedBalance($wallet, self::computePurchasedRemaining(
                self::getPurchasedBalance($wallet),
                (int) $wallet->balanceInt,
            ));
        }

        $this->credit($billable, $type, $quantity, $reason ?? 'subscription_renewal_rollover');
    }

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
    /**
     * @param  bool  $preserveDebt  Keep a negative balance instead of forgiving it.
     *                              Pass this when an overage charge for these units is
     *                              still in flight — see below.
     */
    public function resetAndCredit(
        Billable $billable,
        string $type,
        int $quota,
        string $reason = 'renewal',
        bool $preserveDebt = false,
    ): void {
        $previousBalance = 0;

        DB::transaction(function () use ($billable, $type, $quota, $preserveDebt, &$previousBalance): void {
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
            } elseif ($previousBalance < 0 && ! $preserveDebt) {
                $wallet->deposit(abs($previousBalance), ['type' => $type, 'reason' => 'period_reset']);
            }

            // The quota goes on top of whatever is left. Normally that is zero, because
            // the branch above just levelled the wallet — but when the debt is preserved
            // it is still negative, so the deposit lands on it: −37 + 100 = 63.
            //
            // A negative balance means unpaid debt, and forgiving it while the charge for
            // exactly those units is still settling paid the customer twice: the reset
            // zeroed the debt, then `settleOverageWallets()` credited the charged quantity
            // on top, so every overage cycle handed out that quantity as free units. With
            // SEPA the renewal regularly settles first, because Mollie creates it about
            // two days ahead while the overage charge is created the day before it.
            $newBalance = $quota + $purchasedRemaining;

            if ($newBalance > 0) {
                // `period_credit`, not the generic `credit` that `credit()` also writes.
                // SyncPurchasedBalanceCommand anchors its "purchases this period" window
                // on the renewal's own bookkeeping, and a wallet that renews at exactly
                // balance 0 leaves no `period_reset` — the branch above only fires for a
                // non-zero balance. The window then reached back into earlier periods and
                // resurrected long-consumed purchased credits, which every later reset
                // re-credited. This deposit always happens when there is anything to
                // credit, so it is the marker that is actually there.
                $wallet->deposit($newBalance, ['type' => $type, 'reason' => self::PERIOD_CREDIT_REASON]);
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
     * The renewal's own quota deposit. Distinct from the generic `credit`, so the
     * period boundary is identifiable in the transaction log even when the balance
     * happened to be exactly zero and no `period_reset` was written.
     */
    public const PERIOD_CREDIT_REASON = 'period_credit';

    /**
     * Transaction reasons that represent bookkeeping, not real consumption.
     */
    public const NON_USAGE_REASONS = [
        'credit',
        self::PERIOD_CREDIT_REASON,
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
        // Overage that has been paid for is credited back to the wallet. The
        // consumption was already counted when it was debited — counting the
        // settlement too would report every overage unit twice.
        'overage_settlement',
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

    /**
     * Claim the once-per-period threshold notification for this usage type.
     *
     * Runs inside the debit transaction so two concurrent debits cannot both
     * claim it. Returns the usage percentage to notify about, or null when the
     * threshold is not reached or was already claimed this period.
     */
    private function markThresholdNotified(Billable $billable, string $type, int $balanceAfter): ?int
    {
        if (! $billable instanceof Model) {
            return null;
        }

        $included = $this->catalog->includedUsage(
            $billable->getBillingSubscriptionPlanCode() ?? '',
            $billable->getBillingSubscriptionInterval(),
            $type,
        );

        if ($included <= 0) {
            return null;
        }

        $used = max(0, $included - $balanceAfter);
        $percent = (int) floor(($used / $included) * 100);
        $threshold = (int) config('mollie-billing.usage_threshold_percent', 80);

        if ($percent < $threshold) {
            return null;
        }

        $meta = $billable->getBillingSubscriptionMeta();
        $notified = $meta['usage_threshold_notified_at'][$type] ?? null;
        $periodStart = $billable->getBillingPeriodStartsAt();

        $alreadyNotified = $notified !== null
            && $periodStart !== null
            && Carbon::parse((string) $notified)->setTimezone('UTC')->greaterThanOrEqualTo($periodStart);

        if ($alreadyNotified) {
            return null;
        }

        $meta['usage_threshold_notified_at'][$type] = BillingTime::nowUtc()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();

        return $percent;
    }

    /**
     * Send the threshold notification. Never throws: recorded usage must not
     * depend on the mail transport being up. The marker is already committed, so
     * a failed send is not retried on the next debit — it is logged instead.
     */
    private function sendThresholdNotification(Billable $billable, string $type, int $percent): void
    {
        try {
            $recipients = MollieBilling::notifyBillingAdmins($billable);
            $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

            if ($recipients === []) {
                return;
            }

            Notification::send(
                $recipients,
                MollieBilling::resolveNotification(UsageThresholdNotification::class, $billable, $type, $percent),
            );
        } catch (\Throwable $e) {
            Log::warning('Usage threshold notification failed', [
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'usage_type' => $type,
                'percent' => $percent,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
