<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Tests\SandboxBrowser\Support\CanaryDeliveries;

/**
 * The pure reading of the canary's webhook receiver (tests/SandboxBrowser/Support/CanaryDeliveries):
 * which deliveries count for a payment, how a probe's attempts are summarised and when a later
 * observation is "Mollie changed its retry behaviour".
 */
function canaryDelivery(int $id, string $at, string $payment, string $path = '/billing/webhook', int $status = 200, int $delay = 0, ?string $answeredAt = 'x'): array
{
    return [
        'id' => $id,
        'received_at' => $at,
        'path' => $path,
        'payment_id' => $payment,
        'response_status' => $status,
        'delay_s' => $delay,
        'attempt_no' => 1,
        'answered_at' => $answeredAt === 'x' ? $at : $answeredAt,
    ];
}

it('picks a payment\'s real deliveries in arrival order, never a probe, and from a moment on', function (): void {
    $pool = [
        canaryDelivery(3, '2026-11-27T10:00:05.000Z', 'tr_a'),
        canaryDelivery(1, '2026-11-27T09:00:00.000Z', 'tr_a'),
        canaryDelivery(2, '2026-11-27T09:30:00.000Z', 'tr_b'),
        canaryDelivery(4, '2026-11-27T10:01:00.000Z', 'tr_a', '/probe/503/3/x'),
        canaryDelivery(5, '2026-11-27T09:59:57.000Z', 'tr_a'),
    ];

    expect(array_column(CanaryDeliveries::forPayment($pool, 'tr_a'), 'id'))->toBe([1, 3, 5])
        // 09:59:57 is within the five seconds of clock skew before 10:00:00
        ->and(array_column(CanaryDeliveries::forPayment($pool, 'tr_a', '2026-11-27T10:00:00+00:00'), 'id'))->toBe([3, 5])
        ->and(CanaryDeliveries::forPayment($pool, 'tr_a', '2026-11-27T11:00:00+00:00'))->toBe([])
        ->and(CanaryDeliveries::forPayment($pool, 'tr_c'))->toBe([]);
});

it('reads what an attempt was for Mollie', function (): void {
    expect(CanaryDeliveries::outcome(canaryDelivery(1, '2026-11-27T10:00:00.000Z', 'tr', status: 200)))->toBe('ok')
        ->and(CanaryDeliveries::outcome(canaryDelivery(1, '2026-11-27T10:00:00.000Z', 'tr', status: 503)))->toBe('http-503')
        // held back past Mollie's 15 s
        ->and(CanaryDeliveries::outcome(canaryDelivery(1, '2026-11-27T10:00:00.000Z', 'tr', delay: 20)))->toBe('timeout')
        ->and(CanaryDeliveries::outcome(canaryDelivery(1, '2026-11-27T10:00:00.000Z', 'tr', delay: 15)))->toBe('ok')
        // the answer never left: the platform cut the request
        ->and(CanaryDeliveries::outcome(canaryDelivery(1, '2026-11-27T10:00:00.000Z', 'tr', answeredAt: null)))->toBe('timeout');
});

it('summarises a probe\'s attempts', function (): void {
    $path = '/probe/503/3/t';
    $summary = CanaryDeliveries::retrySummary([
        canaryDelivery(12, '2026-11-27T10:16:00.000Z', 'tr', $path, 200),
        canaryDelivery(10, '2026-11-27T10:00:00.000Z', 'tr', $path, 503),
        canaryDelivery(11, '2026-11-27T10:01:00.000Z', 'tr', $path, 503),
        canaryDelivery(13, '2026-11-27T10:20:00.000Z', 'tr', $path, 200),
    ]);

    expect($summary)->toMatchArray([
        'attempts' => 4,
        'outcomes' => ['http-503', 'http-503', 'ok', 'ok'],
        'first_at' => '2026-11-27T10:00:00.000Z',
        'last_at' => '2026-11-27T10:20:00.000Z',
        'intervals_s' => [60, 900, 240],
        'span_s' => 1200,
        'ended_ok' => true,
        'attempts_after_first_ok' => 1,
    ]);

    expect(CanaryDeliveries::retrySummary([]))->toMatchArray(['attempts' => 0, 'ended_ok' => false, 'span_s' => 0]);
});

it('flags a later observation that differs from the baseline, and only then', function (): void {
    $baseline = ['attempts' => 4, 'ended_ok' => true, 'attempts_after_first_ok' => 0, 'intervals_s' => [60, 300, 900]];

    expect(CanaryDeliveries::retryDeviations('503x3', $baseline, $baseline))->toBe([])
        // jitter within max(120 s, 50 %)
        ->and(CanaryDeliveries::retryDeviations('503x3', $baseline, ['intervals_s' => [170, 440, 1300]] + $baseline))->toBe([]);

    $changed = CanaryDeliveries::retryDeviations('503x3', $baseline, [
        'attempts' => 5,
        'ended_ok' => false,
        'attempts_after_first_ok' => 2,
        'intervals_s' => [60, 900, 900],
    ]);

    expect($changed)->toHaveCount(4)
        ->and($changed[0])->toStartWith('Mollie changed its retry behaviour (503x3): 5 attempts')
        ->and(implode("\n", $changed))->toContain('did not end on a 200')
        ->toContain('2 more calls after a 200')
        ->toContain('retry 3 came 900 s after the previous one, the baseline 300 s');
});

it('compares the never-recovering probe with what Mollie documents', function (): void {
    expect(CanaryDeliveries::documentedDeviations(['attempts' => 10, 'span_s' => 26 * 3600 + 600]))->toBe([])
        ->and(CanaryDeliveries::documentedDeviations(['attempts' => 1, 'span_s' => 0]))->toBe(['1 calls for a webhook that never answers 200; Mollie documents 10'])
        ->and(CanaryDeliveries::documentedDeviations(['attempts' => 10, 'span_s' => 12 * 3600])[0])->toContain('12.0 h after the first');
});

it('starts a probe set at once, then weekly on Mondays, never while one is in flight', function (): void {
    $monday = CarbonImmutable::parse('2026-11-30T08:15:00Z');
    $done = ['created_at' => '2026-11-23T08:15:00+00:00', 'completed_at' => '2026-11-25T08:15:00+00:00'];

    expect(CanaryDeliveries::probeSetDue([], $monday->addDay()))->toBeTrue()
        ->and(CanaryDeliveries::probeSetDue([$done], $monday))->toBeTrue()
        ->and(CanaryDeliveries::probeSetDue([$done], $monday->addDay()))->toBeFalse()
        ->and(CanaryDeliveries::probeSetDue([['created_at' => '2026-11-29T08:15:00+00:00', 'completed_at' => '2026-11-29T09:00:00+00:00']], $monday))->toBeFalse()
        ->and(CanaryDeliveries::probeSetDue([['created_at' => '2026-11-23T08:15:00+00:00', 'completed_at' => null]], $monday))->toBeFalse();
});

it('reads a probe set only past Mollie\'s retry window', function (): void {
    $set = ['created_at' => '2026-11-30T08:15:00+00:00', 'probes' => [
        'a' => ['paid_at' => '2026-11-30T08:16:00+00:00'],
        'b' => ['paid_at' => '2026-11-30T08:18:00+00:00'],
    ]];

    expect(CanaryDeliveries::probeSetReadable($set, CarbonImmutable::parse('2026-12-01T14:17:59Z')))->toBeFalse()
        ->and(CanaryDeliveries::probeSetReadable($set, CarbonImmutable::parse('2026-12-01T14:18:00Z')))->toBeTrue();
});

it('offers the timeout probe only where the receiver serves it past Mollie\'s timeout', function (): void {
    expect(array_keys(CanaryDeliveries::probesFor([])))->toBe(['503x3', '500always'])
        ->and(array_keys(CanaryDeliveries::probesFor(['probe_timeout_enabled' => true, 'probe_timeout_delay_s' => 10])))->toBe(['503x3', '500always'])
        ->and(array_keys(CanaryDeliveries::probesFor(['probe_timeout_enabled' => true, 'probe_timeout_delay_s' => 20])))->toBe(['503x3', '500always', 'timeout2']);
});
