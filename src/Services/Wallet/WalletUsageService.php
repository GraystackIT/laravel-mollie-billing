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
     * usage_rollover is disabled — the wallet is brought to exactly
     * the plan's included quota on each renewal.
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
            && Carbon::parse($notified)->greaterThanOrEqualTo($periodStart);

        if ($alreadyNotified) {
            return;
        }

        $meta['usage_threshold_notified_at'][$type] = now()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();

        $recipients = MollieBilling::notifyBillingAdmins($billable);
        $recipients = is_array($recipients) ? $recipients : iterator_to_array($recipients);

        if ($recipients !== []) {
            Notification::send(
                $recipients,
                new UsageThresholdNotification($billable, $type, $percent),
            );
        }
    }
}
