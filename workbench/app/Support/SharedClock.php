<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One "now" for both processes of the browser suite.
 *
 * The test process and the Dusk server share a database, not a clock: `Carbon::setTestNow()`
 * in the test only moves the test's own time, and every page the browser loads is rendered
 * at the machine's real time. A scenario that needs renewals months apart — a gift that runs
 * out, a trial that ends — could therefore move the books forward but never look at them.
 *
 * The clock is a row in the shared settings table: the instant travelled to, and the real
 * instant at which that happened. Both processes read "now" as that instant plus the real
 * time elapsed since, so the clock keeps running the way it does in production (a cache lock
 * with a lifetime still runs out, two requests are not stamped with the same microsecond),
 * and the two processes never drift apart.
 *
 * Test process: `BrowserTestCase::travelSharedClockTo()` writes the row and sets its own
 * clock. Server: `WorkbenchServiceProvider` reads the row on every request — `php -S` boots
 * a fresh application per request — and only there (`PHP_SAPI === 'cli-server'`), so a row
 * a test left behind can never move the time of the next test's own process. Nothing here
 * is package code; production never sees it.
 */
class SharedClock
{
    public const KEY = 'clock.now';

    /** Move both processes to `$at`, from now on running forward from there. */
    public static function travelTo(CarbonInterface $at): Carbon
    {
        $at = Carbon::instance($at)->utc();

        WorkbenchSetting::set(self::KEY, json_encode([
            'at' => $at->format('Y-m-d\TH:i:s.uP'),
            'real' => microtime(true),
        ]));

        self::applyRunningFrom($at, microtime(true));

        return Carbon::now('UTC');
    }

    /** Back to the machine's clock, in this process and for the server's next request. */
    public static function reset(): void
    {
        $travelled = false;

        try {
            $travelled = WorkbenchSetting::get(self::KEY) !== null;
            WorkbenchSetting::forget(self::KEY);
        } catch (\Throwable) {
            // No settings table (the database was never migrated) — nothing was set.
        }

        Carbon::setTestNow();

        // The two processes share one file cache, and its entries expire by the clock that
        // wrote them. One written in a travelled future (a Mollie subscription snapshot, a
        // lock) would still be fresh for the next test on the machine's time — and that test
        // starts its ids over (`sub_wb_1`, billable 1), so it would read this test's entry
        // as its own.
        if ($travelled) {
            \Illuminate\Support\Facades\Cache::flush();
        }
    }

    /**
     * Read the shared row and set this process's clock from it. Returns whether a clock is
     * set, so the caller can adjust what depends on it.
     */
    public static function applyFromSettings(): bool
    {
        $raw = WorkbenchSetting::get(self::KEY);

        if ($raw === null || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['at'], $data['real'])) {
            return false;
        }

        self::applyRunningFrom(Carbon::parse((string) $data['at'])->utc(), (float) $data['real']);

        return true;
    }

    private static function applyRunningFrom(Carbon $at, float $realAtTravel): void
    {
        $anchor = $at->copy();

        Carbon::setTestNow(static fn () => $anchor->copy()->addMicroseconds(
            (int) round((microtime(true) - $realAtTravel) * 1_000_000)
        ));
    }
}
