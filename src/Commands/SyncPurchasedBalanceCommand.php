<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Console\Command;

/**
 * Backfills `purchased_balance` in the wallet meta from transaction history.
 *
 * Purchase deposits are only counted for the **current** billing period, i.e. since
 * the wallet's most recent renewal bookkeeping transaction. Summing them over the wallet's
 * lifetime resurrected long-consumed credits: with quota 100 000/month and
 * rollover=false, a customer who bought and used 5 000 extra units six months ago got
 * `purchased_balance = 5 000` written right after a renewal (balance 100 000 >= 5 000),
 * and `WalletUsageService::resetAndCredit()` then added those 5 000 on every future
 * reset — a permanent, compounding over-credit.
 */
class SyncPurchasedBalanceCommand extends Command
{
    protected $signature = 'billing:sync-purchased-balance
        {--dry-run : Show what would change without writing anything}
        {--force : Skip the confirmation prompt (for cron / CI)}';

    protected $description = 'Backfill purchased_balance on all wallets from transaction history (one-time orders + coupon credits).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = [];

        foreach (Wallet::all() as $wallet) {
            $current = WalletUsageService::getPurchasedBalance($wallet);

            $purchasedThisPeriod = $this->purchasedSinceLastRenewal($wallet);
            $target = WalletUsageService::computePurchasedRemaining(
                $purchasedThisPeriod,
                (int) $wallet->balanceInt,
            );

            // No early-out on "no purchases": a wallet whose purchases predate the
            // last reset but still carries a positive purchased_balance is exactly the
            // row that needs correcting down to 0.
            if ($current === $target) {
                continue;
            }

            $changes[] = [$wallet, $current, $target];
        }

        if ($changes === []) {
            $this->info('All wallets are already in sync.');

            return self::SUCCESS;
        }

        foreach ($changes as [$wallet, $current, $target]) {
            $arrow = $target < $current ? '<fg=yellow>↓</>' : '<fg=green>↑</>';
            $this->line("  {$arrow} Wallet {$wallet->slug} (#{$wallet->getKey()}): {$current} → {$target}");
        }

        if ($dryRun) {
            $this->comment(sprintf('Dry run — %d wallet(s) would be updated. Nothing was written.', count($changes)));

            return self::SUCCESS;
        }

        // Overwrites a value that directly controls how many units get credited on
        // every future period reset, so it must not happen unprompted.
        if (! $this->option('force')
            && ! $this->confirm(sprintf('Overwrite purchased_balance on %d wallet(s)?', count($changes)), false)) {
            $this->comment('Aborted — nothing was written.');

            return self::SUCCESS;
        }

        foreach ($changes as [$wallet, , $target]) {
            WalletUsageService::setPurchasedBalance($wallet, $target);
        }

        $this->info(sprintf('Synced %d wallet(s).', count($changes)));

        return self::SUCCESS;
    }

    /**
     * Purchase deposits (one-time orders + coupon credits) made since the wallet's
     * last renewal. Anything older was already accounted for by that renewal's own
     * carry-over computation.
     *
     * The anchor is the latest of the two markers a renewal can leave, not
     * `period_reset` alone: that transaction is only written when the balance was
     * non-zero, so a wallet that renewed at exactly 0 had no anchor at all and the
     * window reached back into earlier periods — resurrecting purchased credits the
     * customer had long consumed, which every later `resetAndCredit()` then
     * re-credited on top of the new quota. `period_credit` is written whenever there
     * is any quota to deposit, which is the ordinary case.
     */
    private function purchasedSinceLastRenewal(Wallet $wallet): int
    {
        $query = Transaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('type', 'deposit')
            ->where('confirmed', true)
            ->where(function ($q): void {
                $q->where('meta->reason', 'like', 'one_time_order:%')
                    ->orWhere('meta->reason', 'coupon_credit');
            });

        $lastReset = Transaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->whereIn('meta->reason', ['period_reset', WalletUsageService::PERIOD_CREDIT_REASON])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($lastReset !== null) {
            // Ties on created_at are broken by id: reset and purchase can land in the
            // same second, and a purchase recorded before the reset must not count.
            $query->where(function ($q) use ($lastReset): void {
                $q->where('created_at', '>', $lastReset->created_at)
                    ->orWhere(function ($q2) use ($lastReset): void {
                        $q2->where('created_at', $lastReset->created_at)
                            ->where('id', '>', $lastReset->getKey());
                    });
            });
        }

        return (int) $query->sum('amount');
    }
}
