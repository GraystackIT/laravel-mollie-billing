<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Commands;

use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use Illuminate\Console\Command;

class SyncPurchasedBalanceCommand extends Command
{
    protected $signature = 'billing:sync-purchased-balance';

    protected $description = 'Backfill purchased_balance on all wallets from transaction history (one-time orders + coupon credits).';

    public function handle(): int
    {
        $wallets = Wallet::all();
        $updated = 0;

        foreach ($wallets as $wallet) {
            // Sum all deposits that originated from purchases or coupon credits.
            $totalPurchased = (int) Transaction::query()
                ->where('wallet_id', $wallet->getKey())
                ->where('type', 'deposit')
                ->where('confirmed', true)
                ->where(function ($q) {
                    $q->where('meta->reason', 'like', 'one_time_order:%')
                      ->orWhere('meta->reason', 'coupon_credit');
                })
                ->sum('amount');

            if ($totalPurchased <= 0) {
                continue;
            }

            // Compute how many purchased credits remain given the current balance.
            $balance = (int) $wallet->balanceInt;
            $purchasedRemaining = WalletUsageService::computePurchasedRemaining($totalPurchased, $balance);

            $current = WalletUsageService::getPurchasedBalance($wallet);

            if ($current !== $purchasedRemaining) {
                WalletUsageService::setPurchasedBalance($wallet, $purchasedRemaining);
                $updated++;

                $this->line("  Wallet {$wallet->slug} (#{$wallet->getKey()}): {$current} → {$purchasedRemaining}");
            }
        }

        $this->info("Synced {$updated} wallet(s).");

        return self::SUCCESS;
    }
}
