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
        DB::table('workbench_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    public static function forget(string $key): void
    {
        DB::table('workbench_settings')->where('key', $key)->delete();
    }

    /** Monotonic counter for readable fake-Mollie ids (tr_wb_1, sub_wb_2, …). */
    public static function nextId(string $prefix): string
    {
        return DB::transaction(function () use ($prefix): string {
            $key = 'seq.'.$prefix;
            $current = (int) (self::get($key) ?? 0) + 1;
            self::set($key, (string) $current);

            return sprintf('%s_wb_%d', $prefix, $current);
        });
    }
}
