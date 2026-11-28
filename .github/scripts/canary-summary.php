<?php

declare(strict_types=1);

/*
 * The cloud canary's bookkeeping, for .github/workflows/renewal-canary.yml. The rules live in
 * tests/SandboxBrowser/Support/CanarySummary.php (unit-tested); this file only reads and writes.
 *
 *   php .github/scripts/canary-summary.php should-retry <state.json> <attempt-started-at>
 *       exit 0: the failed attempt died without a finding — retry it; exit 1: do not.
 *
 *   php .github/scripts/canary-summary.php summarise
 *       env: STATE_DIR, RUN_URL, STATE_COMMIT, RESET_CHOICE,
 *            RENEWAL_ATTEMPT_1/2, SUBSCRIPTION_ATTEMPT_1/2 (step outcomes)
 *       writes summary.md, summary.json, issue.md; appends red=true|false to $GITHUB_OUTPUT.
 */

use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanarySummary;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/** @return array<string, mixed>|null */
function canarySummaryReadJson(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/** @return list<string> */
function canarySummaryLogTail(string $path, int $lines = 40): array
{
    if (! is_file($path)) {
        return [];
    }

    // Without the terminal colours Pest writes.
    $all = array_map(static fn (string $l): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $l), file($path, FILE_IGNORE_NEW_LINES) ?: []);

    return array_slice($all, -$lines);
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'should-retry':
        exit(CanarySummary::shouldRetry(canarySummaryReadJson((string) ($argv[2] ?? '')), (string) ($argv[3] ?? '')) ? 0 : 1);

    case 'summarise':
        $dir = (string) getenv('STATE_DIR');
        $runs = [];

        foreach (['renewal_replay' => 'RENEWAL', 'subscription' => 'SUBSCRIPTION'] as $key => $env) {
            $runs[$key] = [
                'attempts' => [(string) getenv($env.'_ATTEMPT_1'), (string) getenv($env.'_ATTEMPT_2')],
                'state' => canarySummaryReadJson($dir.'/'.CanarySummary::CANARIES[$key]['state']),
                'log_tail' => canarySummaryLogTail($key.'.log'),
            ];
        }

        $summary = CanarySummary::build($runs, [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_url' => (string) getenv('RUN_URL'),
            'state_commit' => getenv('STATE_COMMIT') ?: null,
            'reset' => getenv('RESET_CHOICE') ?: 'no',
        ]);

        file_put_contents('summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents('summary.md', CanarySummary::markdown($summary));
        file_put_contents('issue.md', CanarySummary::issueBody($summary));

        if (($output = getenv('GITHUB_OUTPUT')) !== false && $output !== '') {
            file_put_contents($output, 'red='.($summary['outcome'] === 'red' ? 'true' : 'false')."\n", FILE_APPEND);
        }

        exit(0);

    default:
        fwrite(STDERR, "usage: canary-summary.php should-retry <state.json> <started-at> | summarise\n");

        exit(2);
}
