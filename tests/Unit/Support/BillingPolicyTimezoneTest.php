<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Support\BillingPolicy;

it('prorataPeriodDays returns the same result for UTC and non-UTC inputs', function (): void {
    $startUtc = CarbonImmutable::create(2026, 5, 1, 0, 0, 0, 'UTC');
    $endUtc = CarbonImmutable::create(2026, 6, 1, 0, 0, 0, 'UTC');

    $startBerlin = $startUtc->setTimezone('Europe/Berlin');
    $endBerlin = $endUtc->setTimezone('Europe/Berlin');

    $resultUtc = BillingPolicy::prorataPeriodDays($startUtc, $endUtc);
    $resultBerlin = BillingPolicy::prorataPeriodDays($startBerlin, $endBerlin);

    expect($resultBerlin)->toBe($resultUtc);
    expect($resultUtc['total'])->toBe(31);
});

it('prorataPeriodDays computes remaining days deterministically near midnight UTC', function (): void {
    // Freeze "now" so the test is reproducible.
    $now = CarbonImmutable::create(2026, 5, 15, 23, 30, 0, 'UTC');
    \Carbon\Carbon::setTestNow($now);
    \Carbon\CarbonImmutable::setTestNow($now);

    try {
        $start = CarbonImmutable::create(2026, 5, 1, 0, 0, 0, 'UTC');
        $end = CarbonImmutable::create(2026, 6, 1, 0, 0, 0, 'UTC');

        $result = BillingPolicy::prorataPeriodDays($start, $end);

        expect($result['total'])->toBe(31);
        expect($result['remaining'])->toBe(17); // 2026-05-15 → 2026-06-01 = 17 days
    } finally {
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
    }
});
