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

    /**
     * Every transaction a renewal writes. `period_reset` is the withdrawal (absent when the
     * balance was already zero), `period_credit` the deposit, and
     * `subscription_renewal_rollover` what a rollover usage type writes — the last of which
     * was missing from the old anchor list entirely, so those wallets were reconciled
     * against their whole lifetime.
     */
    private const RENEWAL_MARKER_REASONS = [
        'period_reset',
        WalletUsageService::PERIOD_CREDIT_REASON,
        'subscription_renewal_rollover',
        // A plan change is the other place the live bookkeeping clamps: the adjuster
        // writes `min(purchasedRemaining, finalBalance)`. Left out of this list, a pack
        // consumed before an upgrade was measured against the *new* plan's balance at the
        // very end — 490 used units of a 500 pack came back as purchased, out of a quota
        // of 1 000, and survived every reset from then on.
        'plan_change_reset',
        'plan_change_credit',
        'plan_change_pending_overage',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = [];

        // Chunked, not `all()`. Every wallet's transaction history is replayed here, and the
        // cleanup jobs already record what loading a whole table into a worker costs — the
        // backfill died before writing anything, with no partial progress to show.
        Wallet::query()->chunkById(200, function ($wallets) use (&$changes): void {
            foreach ($wallets as $wallet) {
                $current = WalletUsageService::getPurchasedBalance($wallet);

                $target = $this->purchasedFromHistory($wallet);

                // No early-out on "no purchases": a wallet whose purchases were fully consumed
                // but still carries a positive purchased_balance is exactly the row that needs
                // correcting down to 0. The replay distinguishes that from a purchase the last
                // renewal legitimately carried forward, which has no transaction of its own.
                if ($current === $target) {
                    continue;
                }

                $changes[] = [$wallet, $current, $target];
            }
        });

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
     * Replay the wallet's whole transaction history to work out how much of its current
     * balance was paid for.
     *
     * A window ("purchases since the last renewal") cannot answer this, because
     * `resetAndCredit()` deliberately *carries* an unconsumed purchase forward by
     * re-persisting `purchased_balance` — so a pack bought in June and still unused in
     * July has no transaction in July at all. Summing the window then produced 0 and this
     * command wrote it, after which the next renewal withdrew the whole balance and
     * credited only the plan quota: credits the customer had paid for, gone. The mirror
     * failure was a rollover wallet, whose renewal writes `subscription_renewal_rollover`
     * and so matched no anchor at all, leaving a lifetime sum that relabelled plan units
     * as purchased.
     *
     * The replay is exact instead: purchases add, and every renewal clamps the running
     * total against the balance it found, which is precisely what `resetAndCredit()` does.
     */
    private function purchasedFromHistory(Wallet $wallet): int
    {
        $purchased = 0;
        $balance = 0;
        $clampedForThisRenewal = false;

        Transaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('confirmed', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (Transaction $transaction) use (&$purchased, &$balance, &$clampedForThisRenewal): void {
                $reason = $transaction->meta['reason'] ?? null;
                $amount = (int) $transaction->amount;
                $isRenewalMarker = is_string($reason) && in_array($reason, self::RENEWAL_MARKER_REASONS, true);

                // A renewal is where the carry-over is decided: what survives is what the
                // balance at that moment could still cover. Once per renewal, though — the
                // withdrawal and the deposit are one event, and clamping again after the
                // withdrawal would measure against a balance of zero and discard what the
                // renewal had just legitimately carried forward.
                if ($isRenewalMarker && ! $clampedForThisRenewal) {
                    $purchased = max(0, min($purchased, $balance));
                    $clampedForThisRenewal = true;
                } elseif (! $isRenewalMarker) {
                    // Leaving a clamp point: what it carried forward cannot exceed the
                    // balance it left behind. A renewal always leaves at least that much; a
                    // plan change that netted an excess against the new quota may not.
                    if ($clampedForThisRenewal) {
                        $purchased = max(0, min($purchased, $balance));
                    }

                    $clampedForThisRenewal = false;
                }

                $balance += $amount;

                if (is_string($reason) && (str_starts_with($reason, 'one_time_order:') || $reason === 'coupon_credit')) {
                    $purchased += $amount;
                }
            });

        return max(0, min($purchased, (int) $wallet->balanceInt));
    }
}
