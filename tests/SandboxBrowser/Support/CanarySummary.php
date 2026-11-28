<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser\Support;

/**
 * What the cloud canary's run says, for people (summary.md, the issue) and in a machine-readable
 * form (summary.json, in the run's artifact) — pure, pinned by `tests/Unit/Canary/CanarySummaryTest`.
 * Driven by `.github/scripts/canary-summary.php` in `.github/workflows/renewal-canary.yml`.
 *
 * A canary's run is
 *  - `red`: its last attempt failed, or its state holds problems — an issue;
 *  - `recovered`: the first attempt died without a finding and the retry passed — a note, no issue;
 *  - `green`: passed or incomplete (nothing new) on the first attempt;
 *  - `skipped`: not run.
 * Warnings (the receiver unreachable, a half-built world rebuilt) never make a run red.
 */
final class CanarySummary
{
    public const CANARIES = [
        'renewal_replay' => ['test' => 'RenewalReplayCanaryTest', 'title' => 'Renewal replay canary', 'state' => 'renewal-replay.json'],
        'subscription' => ['test' => 'SubscriptionCanaryTest', 'title' => 'Subscription canary', 'state' => 'state.json'],
    ];

    /**
     * Whether a failed attempt is worth one more: only when it died without a finding. An
     * attempt that finished — or died — with problems written down is a finding, and a second
     * attempt would not see them again (the renewal canary books each payment once).
     *
     * @param  array<string, mixed>|null  $state  the canary's state after the attempt
     * @param  string  $startedAt  when the attempt started (UTC, ISO 8601)
     */
    public static function shouldRetry(?array $state, string $startedAt): bool
    {
        $since = strtotime($startedAt);

        foreach (array_reverse($state['visits'] ?? []) as $visit) {
            $at = strtotime((string) ($visit['at'] ?? ''));

            if ($at === false || $since === false || $at < $since) {
                break;
            }

            if (($visit['problems'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{attempts: list<string>, state: ?array<string, mixed>, log_tail?: list<string>}>  $runs  by canary key
     * @param  array{run_url: string, state_commit: ?string, reset?: string, generated_at: string}  $context
     * @return array<string, mixed>
     */
    public static function build(array $runs, array $context): array
    {
        $canaries = [];

        foreach (self::CANARIES as $key => $meta) {
            $run = $runs[$key] ?? ['attempts' => [], 'state' => null];
            $attempts = array_values(array_filter($run['attempts'], static fn (string $a): bool => $a !== '' && $a !== 'skipped'));
            $state = $run['state'];
            $visits = $state['visits'] ?? [];
            $last = $visits === [] ? [] : end($visits);
            $problems = array_values($state['last_problems'] ?? []);
            $final = $attempts === [] ? 'skipped' : end($attempts);

            $status = match (true) {
                $attempts === [] => 'skipped',
                $final !== 'success' || $problems !== [] => 'red',
                count($attempts) > 1 => 'recovered',
                default => 'green',
            };

            $aborted = array_values(array_filter(array_map(static fn (array $v): ?string => $v['aborted'] ?? null, $visits)));

            $canaries[] = [
                'canary' => $key,
                'test' => $meta['test'],
                'title' => $meta['title'],
                'status' => $status,
                'attempts' => $attempts,
                'problems' => $problems,
                'warnings' => array_values($state['last_warnings'] ?? ($last['warnings'] ?? [])),
                'observations' => array_values($last['observations'] ?? []),
                'recovered_from' => $status === 'recovered' ? (end($aborted) ?: 'the first attempt failed before writing its state') : null,
                'last_visit_at' => $last['at'] ?? null,
                'log_tail' => array_values($run['log_tail'] ?? []),
            ];
        }

        $red = array_values(array_filter($canaries, static fn (array $c): bool => $c['status'] === 'red'));

        return [
            'schema' => 1,
            'generated_at' => $context['generated_at'],
            'run_url' => $context['run_url'],
            'state_commit' => $context['state_commit'],
            'reset' => $context['reset'] ?? 'no',
            'outcome' => $red === [] ? 'green' : 'red',
            'failing_tests' => array_column($red, 'test'),
            'problems' => array_merge(...array_map(static fn (array $c): array => array_map(static fn (string $p): string => "{$c['test']}: {$p}", $c['problems']), $canaries)),
            'warnings' => array_merge(...array_map(static fn (array $c): array => array_map(static fn (string $w): string => "{$c['test']}: {$w}", $c['warnings']), $canaries)),
            'canaries' => $canaries,
        ];
    }

    /** @param array<string, mixed> $summary */
    public static function markdown(array $summary): string
    {
        $out = '### Canary visit, '.substr((string) $summary['generated_at'], 0, 16).' UTC — **'.$summary['outcome']."**\n\n";
        $out .= 'Run: '.$summary['run_url'].' · canary-state: `'.($summary['state_commit'] ?? 'unknown')."`\n\n";

        foreach ($summary['canaries'] as $canary) {
            $out .= '#### '.$canary['title'].' (`'.$canary['test'].'`): **'.$canary['status'].'**';
            $out .= $canary['attempts'] === [] ? "\n\n" : ' — attempts: '.implode(' → ', $canary['attempts'])."\n\n";

            if ($canary['recovered_from'] !== null) {
                $out .= '> Recovered after a retry. The first attempt died: '.$canary['recovered_from']."\n\n";
            }

            foreach (['problems' => 'Problems', 'warnings' => 'Warnings', 'observations' => 'Seen on this visit'] as $field => $label) {
                if ($canary[$field] !== []) {
                    $out .= "**{$label}**\n\n- ".implode("\n- ", $canary[$field])."\n\n";
                }
            }

            if ($canary['log_tail'] !== []) {
                $out .= "<details><summary>Test output (last lines)</summary>\n\n```\n".implode("\n", $canary['log_tail'])."\n```\n</details>\n\n";
            }
        }

        return $out;
    }

    /**
     * The issue body: the summary, then a fenced JSON block with the run, the commit, the failing tests and the problems.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function issueBody(array $summary): string
    {
        $block = [
            'run_url' => $summary['run_url'],
            'state_commit' => $summary['state_commit'],
            'failing_tests' => $summary['failing_tests'],
            'problems' => $summary['problems'],
        ];

        return self::markdown($summary)
            ."```json canary\n".json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n```\n";
    }
}
