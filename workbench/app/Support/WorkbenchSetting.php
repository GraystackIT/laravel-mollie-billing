<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cross-process switches for the browser suite.
 *
 * The Dusk server and the test process are separate PHP processes; a static
 * property or config() set in one is invisible to the other. This table is the
 * only channel both share, so everything a test wants to change about the
 * server's behaviour (fake IP country, the outcome of the next recurring
 * charge) goes through here.
 */
class WorkbenchSetting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = DB::table('workbench_settings')->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        self::retryingOnLock(static function () use ($key, $value): void {
            DB::table('workbench_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        });
    }

    public static function forget(string $key): void
    {
        DB::table('workbench_settings')->where('key', $key)->delete();
    }

    /** Monotonic counter for readable fake-Mollie ids (tr_wb_1, sub_wb_2, …). */
    public static function nextId(string $prefix): string
    {
        return self::retryingOnLock(static function () use ($prefix): string {
            return DB::transaction(function () use ($prefix): string {
                $key = 'seq.'.$prefix;
                $current = (int) (self::get($key) ?? 0) + 1;

                DB::table('workbench_settings')->updateOrInsert(['key' => $key], ['value' => (string) $current]);

                return sprintf('%s_wb_%d', $prefix, $current);
            });
        });
    }

    /**
     * Run a write again when SQLite answers "database is locked".
     *
     * `busy_timeout` cannot cover this one. The two processes share one database file, and a
     * transaction that *reads* before it *writes* — which `nextId()` must, to increment — starts
     * as a deferred read transaction and then asks to upgrade to a write. If the other process
     * already holds the write lock, SQLite refuses that upgrade **immediately** rather than
     * waiting, because waiting could deadlock two transactions that each hold a read lock. The
     * busy handler is never consulted, so a 10-second timeout makes no difference.
     *
     * A bounded retry is the honest fix at this layer: the collision is a property of two
     * processes sharing a file, the work is idempotent, and losing a run to it costs seven
     * minutes. It surfaced as `test_a_reverse_charged_invoice_shows_no_vat` failing two ways in
     * two runs — once as a lock, once as an invoice that carried VAT because the billing-data
     * save (atomic: billable + VIES validation) had been rolled back by the same lock.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private static function retryingOnLock(callable $write): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return $write();
            } catch (\Illuminate\Database\QueryException $e) {
                $attempts++;

                if ($attempts >= 25 || ! str_contains(strtolower($e->getMessage()), 'database is locked')) {
                    throw $e;
                }

                // 20 ms, growing to a few hundred: long enough for the sibling's transaction to
                // commit, short enough that a test never notices.
                usleep(min(20_000 * $attempts, 300_000));
            }
        }
    }
}
