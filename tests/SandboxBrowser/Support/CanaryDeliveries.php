<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser\Support;

use Carbon\CarbonImmutable;

/**
 * Pure reading of what the canary's webhook receiver recorded — no network, no database, so
 * `tests/Unit/Canary/CanaryDeliveriesTest` pins every rule here.
 *
 * A delivery is a row of `GET /deliveries`: `id`, `received_at` (UTC, milliseconds), `path`,
 * `payment_id`, `response_status`, `delay_s` (the answer held back on purpose), `attempt_no`,
 * `answered_at` (NULL when the request died before the answer left). The receiver is its own
 * repository, `mollie-canary-receiver` (see docs/sandbox-testing.md).
 */
final class CanaryDeliveries
{
    public const PROBE_PREFIX = '/probe/';

    /** Mollie: "Our webhook calls time out after 15 seconds." (docs.mollie.com/reference/webhooks) */
    public const MOLLIE_TIMEOUT_SECONDS = 15;

    /** Mollie: "In total we will call your webhook 10 times", the tenth "26 hours" after the first. */
    public const DOCUMENTED_CALLS = 10;

    public const DOCUMENTED_SPAN_HOURS = 26;

    /**
     * The retry probes: a standalone test payment each, whose webhook URL tells the receiver
     * how to fail. Name => path prefix; the set's tag is appended. `timeout2` only where the
     * receiver reports the timeout probe enabled (it is off by default there).
     */
    public const PROBES = [
        '503x3' => '/probe/503/3',
        '500always' => '/probe/500/always',
        'timeout2' => '/probe/timeout/2',
    ];

    /**
     * The probes a receiver with these settings can serve.
     *
     * @param  array<string, mixed>  $receiverConfig  `config` of `GET /deliveries`
     * @return array<string, string>
     */
    public static function probesFor(array $receiverConfig): array
    {
        $timeout = ($receiverConfig['probe_timeout_enabled'] ?? false) === true
            && (int) ($receiverConfig['probe_timeout_delay_s'] ?? 0) > self::MOLLIE_TIMEOUT_SECONDS;

        return $timeout ? self::PROBES : array_diff_key(self::PROBES, ['timeout2' => true]);
    }

    /** A probe set is read once the documented retry window (26 h) is past, with margin. */
    public const PROBE_READ_AFTER_HOURS = 30;

    /** How far a delivery's clock may run ahead of ours and still count as "after". */
    public const CLOCK_SKEW_SECONDS = 5;

    /** @param array<string, mixed> $delivery */
    public static function isProbe(array $delivery): bool
    {
        return str_starts_with((string) ($delivery['path'] ?? ''), self::PROBE_PREFIX);
    }

    /**
     * The real deliveries of one payment to the package's webhook (no probes), in arrival order,
     * optionally only those received at or after `$notBefore` (less the clock skew).
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    public static function forPayment(array $pool, string $paymentId, ?string $notBefore = null): array
    {
        $from = $notBefore === null ? null : CarbonImmutable::parse($notBefore)->subSeconds(self::CLOCK_SKEW_SECONDS);

        $rows = array_values(array_filter($pool, static fn (array $d): bool => ! self::isProbe($d)
            && ($d['payment_id'] ?? null) === $paymentId
            && ($from === null || CarbonImmutable::parse((string) $d['received_at'])->greaterThanOrEqualTo($from))));

        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /**
     * What one attempt amounted to for Mollie: `ok`, `timeout` (answer held back past Mollie's
     * 15 s, or never sent), or `http-<status>`.
     */
    public static function outcome(array $delivery): string
    {
        if ((int) ($delivery['delay_s'] ?? 0) > self::MOLLIE_TIMEOUT_SECONDS
            || (array_key_exists('answered_at', $delivery) && $delivery['answered_at'] === null)) {
            return 'timeout';
        }

        $status = (int) ($delivery['response_status'] ?? 0);

        return $status === 200 ? 'ok' : 'http-'.$status;
    }

    /**
     * The attempts of one probe payment, summarised.
     *
     * @param  list<array<string, mixed>>  $attempts
     * @return array{attempts: int, outcomes: list<string>, first_at: ?string, last_at: ?string, intervals_s: list<int>, span_s: int, ended_ok: bool, attempts_after_first_ok: int}
     */
    public static function retrySummary(array $attempts): array
    {
        usort($attempts, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        $outcomes = array_map(self::outcome(...), $attempts);
        $times = array_map(static fn (array $d): CarbonImmutable => CarbonImmutable::parse((string) $d['received_at']), $attempts);
        $intervals = [];

        for ($i = 1; $i < count($times); $i++) {
            $intervals[] = (int) round($times[$i]->diffInSeconds($times[$i - 1], true));
        }

        $firstOk = array_search('ok', $outcomes, true);

        return [
            'attempts' => count($attempts),
            'outcomes' => $outcomes,
            'first_at' => $attempts === [] ? null : (string) $attempts[0]['received_at'],
            'last_at' => $attempts === [] ? null : (string) end($attempts)['received_at'],
            'intervals_s' => $intervals,
            'span_s' => $times === [] ? 0 : (int) round(end($times)->diffInSeconds($times[0], true)),
            'ended_ok' => $outcomes !== [] && end($outcomes) === 'ok',
            'attempts_after_first_ok' => $firstOk === false ? 0 : count($outcomes) - $firstOk - 1,
        ];
    }

    /**
     * Where an observation differs from the baseline — each line a problem.
     *
     * Intervals are allowed max(120 s, 50 %) of play: the first observation is a single sample of
     * a scheduler whose jitter nobody has measured yet; the count, the outcomes and "a 200 ends
     * the chain" are held exactly.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $observed
     * @return list<string>
     */
    public static function retryDeviations(string $probe, array $baseline, array $observed): array
    {
        $prefix = "Mollie changed its retry behaviour ({$probe}): ";
        $problems = [];

        if ($observed['attempts'] !== $baseline['attempts']) {
            $problems[] = $prefix."{$observed['attempts']} attempts, the baseline had {$baseline['attempts']}";
        }

        if ($observed['ended_ok'] !== $baseline['ended_ok']) {
            $problems[] = $prefix.'the chain '.($observed['ended_ok'] ? 'ended' : 'did not end').' on a 200, the baseline\'s '.($baseline['ended_ok'] ? 'did' : 'did not');
        }

        if ($observed['attempts_after_first_ok'] > 0) {
            $problems[] = $prefix."{$observed['attempts_after_first_ok']} more calls after a 200";
        }

        foreach ($baseline['intervals_s'] as $i => $expected) {
            $actual = $observed['intervals_s'][$i] ?? null;

            if ($actual === null) {
                break;
            }

            if (abs($actual - $expected) > max(120, (int) ($expected * 0.5))) {
                $problems[] = $prefix.'retry '.($i + 2)." came {$actual} s after the previous one, the baseline {$expected} s";
            }
        }

        return $problems;
    }

    /**
     * Where a summary of the never-recovering probe differs from Mollie's documentation —
     * recorded with the baseline, not a failure: the documentation is not the measurement.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    public static function documentedDeviations(array $summary): array
    {
        $notes = [];

        if ($summary['attempts'] !== self::DOCUMENTED_CALLS) {
            $notes[] = "{$summary['attempts']} calls for a webhook that never answers 200; Mollie documents ".self::DOCUMENTED_CALLS;
        }

        $hours = $summary['span_s'] / 3600;

        if ($summary['attempts'] > 1 && abs($hours - self::DOCUMENTED_SPAN_HOURS) > 2) {
            $notes[] = sprintf('the last call %.1f h after the first; Mollie documents %d h', $hours, self::DOCUMENTED_SPAN_HOURS);
        }

        return $notes;
    }

    /**
     * Whether a visit starts a new probe set: never while one is in flight; the first one at
     * once; then on Mondays, at least six days after the previous one.
     *
     * @param  list<array<string, mixed>>  $sets
     */
    public static function probeSetDue(array $sets, CarbonImmutable $now): bool
    {
        foreach ($sets as $set) {
            if (($set['completed_at'] ?? null) === null) {
                return false;
            }
        }

        if ($sets === []) {
            return true;
        }

        $last = CarbonImmutable::parse((string) end($sets)['created_at']);

        return $now->isMonday() && $last->diffInDays($now, true) >= 6;
    }

    /** @param array<string, mixed> $set */
    public static function probeSetReadable(array $set, CarbonImmutable $now): bool
    {
        $paid = array_filter(array_map(static fn (array $p): ?string => $p['paid_at'] ?? null, $set['probes'] ?? []));
        $latest = $paid === [] ? (string) $set['created_at'] : max($paid);

        return CarbonImmutable::parse($latest)->addHours(self::PROBE_READ_AFTER_HOURS)->lessThanOrEqualTo($now);
    }
}
