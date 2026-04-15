<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Services\Wallet;

use Bavix\Wallet\Models\Wallet as WalletModel;
use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\UsageLimitReached;
use GraystackIT\MollieBilling\Events\WalletCredited;
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
    public function debit(Billable $billable, string $type, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($billable, $type, $quantity): void {
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
                'reason' => 'usage',
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
    public function credit(Billable $billable, string $type, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($billable, $type, $quantity): void {
            $wallet = $this->resolveWallet($billable, $type);
            $wallet->deposit($quantity, [
                'type' => $type,
                'reason' => 'credit',
            ]);
        });

        event(new WalletCredited($billable, $type, $quantity, 'credit'));
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
