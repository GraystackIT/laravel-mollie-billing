<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanarySummary;

/**
 * The cloud canary's verdict (tests/SandboxBrowser/Support/CanarySummary): when a failed visit is
 * retried, when a run is red, recovered or green, and what summary.json and the issue carry.
 */
function canarySummaryState(array $visits, array $problems = [], array $warnings = []): array
{
    return ['visits' => $visits, 'last_problems' => $problems, 'last_warnings' => $warnings];
}

function canarySummaryContext(): array
{
    return ['generated_at' => '2026-11-28T08:20:00Z', 'run_url' => 'https://github.test/runs/1', 'state_commit' => 'abc123'];
}

it('retries an attempt that died before writing anything, or wrote an abort without a finding', function (): void {
    $earlier = ['at' => '2026-11-27T08:20:00+00:00', 'problems' => ['yesterday\'s finding']];

    expect(CanarySummary::shouldRetry(null, '2026-11-28T08:15:00Z'))->toBeTrue()
        ->and(CanarySummary::shouldRetry(canarySummaryState([$earlier]), '2026-11-28T08:15:00Z'))->toBeTrue()
        ->and(CanarySummary::shouldRetry(canarySummaryState([$earlier, ['at' => '2026-11-28T08:16:00+00:00', 'aborted' => 'ConnectionException: timeout', 'problems' => []]]), '2026-11-28T08:15:00Z'))->toBeTrue();
});

it('does not retry an attempt that wrote a finding down — the retry would not see it again', function (): void {
    $finished = ['at' => '2026-11-28T08:16:00+00:00', 'problems' => ['renewal tr_1: invoiced 2400 cents, Mollie collected 1200']];
    $abortedWithFinding = ['at' => '2026-11-28T08:16:00+00:00', 'aborted' => 'RuntimeException: x', 'problems' => ['overage tr_2: Mollie did not deliver']];

    expect(CanarySummary::shouldRetry(canarySummaryState([$finished]), '2026-11-28T08:15:00Z'))->toBeFalse()
        ->and(CanarySummary::shouldRetry(canarySummaryState([$abortedWithFinding]), '2026-11-28T08:15:00Z'))->toBeFalse();
});

it('is green on a first attempt that passed or was incomplete, and red with problems or a failed last attempt', function (): void {
    $green = CanarySummary::build([
        'renewal_replay' => ['attempts' => ['success', 'skipped'], 'state' => canarySummaryState([['at' => 'x', 'observations' => ['seen']]], [], ['Receiver unreachable (HTTP 502): …'])],
        'subscription' => ['attempts' => ['success', ''], 'state' => canarySummaryState([])],
    ], canarySummaryContext());

    expect($green['outcome'])->toBe('green')
        ->and($green['failing_tests'])->toBe([])
        ->and($green['warnings'])->toBe(['RenewalReplayCanaryTest: Receiver unreachable (HTTP 502): …'])
        ->and(array_column($green['canaries'], 'status'))->toBe(['green', 'green']);

    $red = CanarySummary::build([
        'renewal_replay' => ['attempts' => ['failure', 'failure'], 'state' => null],
        'subscription' => ['attempts' => ['success'], 'state' => canarySummaryState([], ['The canary\'s world … is dead'])],
    ], canarySummaryContext());

    expect($red['outcome'])->toBe('red')
        ->and($red['failing_tests'])->toBe(['RenewalReplayCanaryTest', 'SubscriptionCanaryTest'])
        ->and($red['problems'])->toBe(['SubscriptionCanaryTest: The canary\'s world … is dead']);
});

it('calls a failed attempt followed by a passing retry recovered, not red', function (): void {
    $summary = CanarySummary::build([
        'renewal_replay' => ['attempts' => ['failure', 'success'], 'state' => canarySummaryState([
            ['at' => '2026-11-28T08:16:00+00:00', 'aborted' => 'ConnectionException: cURL error 28', 'problems' => []],
            ['at' => '2026-11-28T08:21:00+00:00', 'observations' => [], 'problems' => []],
        ])],
        'subscription' => ['attempts' => ['skipped', 'skipped'], 'state' => null],
    ], canarySummaryContext());

    expect($summary['outcome'])->toBe('green')
        ->and($summary['canaries'][0]['status'])->toBe('recovered')
        ->and($summary['canaries'][0]['recovered_from'])->toBe('ConnectionException: cURL error 28')
        ->and($summary['canaries'][1]['status'])->toBe('skipped')
        ->and(CanarySummary::markdown($summary))->toContain('Recovered after a retry');
});

it('puts the run, the commit, the failing tests and the problems into a fenced block of the issue', function (): void {
    $summary = CanarySummary::build([
        'renewal_replay' => ['attempts' => ['success'], 'state' => canarySummaryState([], ['renewal tr_1: paid, 2 invoices instead of one'])],
        'subscription' => ['attempts' => ['success'], 'state' => canarySummaryState([])],
    ], canarySummaryContext());

    $body = CanarySummary::issueBody($summary);
    preg_match('/```json canary\n(.*?)\n```/s', $body, $m);

    expect(json_decode($m[1] ?? '', true))->toBe([
        'run_url' => 'https://github.test/runs/1',
        'state_commit' => 'abc123',
        'failing_tests' => ['RenewalReplayCanaryTest'],
        'problems' => ['RenewalReplayCanaryTest: renewal tr_1: paid, 2 invoices instead of one'],
    ])->and($summary['schema'])->toBe(1);
});
