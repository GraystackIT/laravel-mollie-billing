<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingPolicy;

// ── prorataFactor ──────────────────────────────────────────────────────────────

it('returns 1.0 when the full period remains (change on first day)', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(30)->startOfDay();

    expect(BillingPolicy::prorataFactor($start, $end))->toBe(1.0);
});

it('returns 0.5 when half the period remains', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    expect(BillingPolicy::prorataFactor($start, $end))->toBe(0.5);
});

it('returns 0.0 when the period has ended (change on last day)', function (): void {
    $start = now()->subDays(30)->startOfDay();
    $end = now()->startOfDay();

    expect(BillingPolicy::prorataFactor($start, $end))->toBe(0.0);
});

it('returns 0.0 for a zero-length period', function (): void {
    $date = now()->startOfDay();

    expect(BillingPolicy::prorataFactor($date, $date))->toBe(0.0);
});

it('returns correct factor for yearly period at quarter mark', function (): void {
    // 365-day period, 90 days elapsed → ~275 remaining
    $start = now()->subDays(90)->startOfDay();
    $end = now()->addDays(275)->startOfDay();

    $factor = BillingPolicy::prorataFactor($start, $end);

    expect($factor)->toBeGreaterThan(0.74);
    expect($factor)->toBeLessThan(0.76);
});

// ── prorataPeriodDays ──────────────────────────────────────────────────────────

it('returns total and remaining days for a full period', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(30)->startOfDay();

    expect(BillingPolicy::prorataPeriodDays($start, $end))->toBe([
        'total' => 30,
        'remaining' => 30,
    ]);
});

it('returns half remaining at the midpoint', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    expect(BillingPolicy::prorataPeriodDays($start, $end))->toBe([
        'total' => 30,
        'remaining' => 15,
    ]);
});

it('returns zero remaining at the period end', function (): void {
    $start = now()->subDays(30)->startOfDay();
    $end = now()->startOfDay();

    expect(BillingPolicy::prorataPeriodDays($start, $end))->toBe([
        'total' => 30,
        'remaining' => 0,
    ]);
});

it('returns zero days for a zero-length period', function (): void {
    $date = now()->startOfDay();

    expect(BillingPolicy::prorataPeriodDays($date, $date))->toBe([
        'total' => 0,
        'remaining' => 0,
    ]);
});

// ── computeProrata: same interval ──────────────────────────────────────────────

it('computes upgrade charge for same-interval plan change', function (): void {
    // €10 → €20/month, 50% remaining → charge = (20-10) * 0.5 = €5
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(1000, 2000, false, $start, $end);

    expect($result['charge_net'])->toBe(500);
    expect($result['credit_net'])->toBe(0);
    expect($result['factor'])->toBe(0.5);
});

it('computes downgrade credit for same-interval plan change', function (): void {
    // €20 → €10/month, 50% remaining → credit = (20-10) * 0.5 = €5
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(2000, 1000, false, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(500);
});

it('returns zero charge and credit for same-interval same-price change', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(1000, 1000, false, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(0);
});

it('returns zero charge and credit when factor is 0 (period ended)', function (): void {
    $start = now()->subDays(30)->startOfDay();
    $end = now()->startOfDay();

    $result = BillingPolicy::computeProrata(1000, 2000, false, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(0);
    expect($result['factor'])->toBe(0.0);
});

it('charges full difference when factor is 1 (change on first day)', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(30)->startOfDay();

    $result = BillingPolicy::computeProrata(1000, 3000, false, $start, $end);

    expect($result['charge_net'])->toBe(2000);
    expect($result['credit_net'])->toBe(0);
    expect($result['factor'])->toBe(1.0);
});

// ── computeProrata: interval change ────────────────────────────────────────────

it('computes charge for monthly-to-yearly upgrade', function (): void {
    // Monthly €29 → Yearly €290, 50% of month remaining
    // unusedCredit = 2900 * 0.5 = 1450
    // netDue = 29000 - 1450 = 27550
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(2900, 29000, true, $start, $end);

    expect($result['charge_net'])->toBe(27550);
    expect($result['credit_net'])->toBe(1450);
});

it('computes refund for yearly-to-monthly downgrade', function (): void {
    // Yearly €290 → Monthly €29, 50% of year remaining
    // unusedCredit = 29000 * 0.5 = 14500
    // netDue = 2900 - 14500 = -11600
    // creditNet = 11600
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(29000, 2900, true, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(11600);
});

it('computes exact break-even when unused credit equals new price', function (): void {
    // Current €200/year, 50% remaining → unusedCredit = 10000
    // New plan = €100/month → netDue = 10000 - 10000 = 0
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(20000, 10000, true, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(0);
});

it('returns zero for interval change when factor is 0', function (): void {
    $start = now()->subDays(30)->startOfDay();
    $end = now()->startOfDay();

    // unusedCredit = 29000 * 0 = 0, netDue = 2900 - 0 = 2900
    $result = BillingPolicy::computeProrata(29000, 2900, true, $start, $end);

    expect($result['charge_net'])->toBe(2900);
    expect($result['credit_net'])->toBe(0);
});

it('credits full current amount for interval change on first day', function (): void {
    $start = now()->startOfDay();
    $end = now()->addDays(30)->startOfDay();

    // factor=1 → unusedCredit = 2900 * 1 = 2900
    // netDue = 29000 - 2900 = 26100
    $result = BillingPolicy::computeProrata(2900, 29000, true, $start, $end);

    expect($result['charge_net'])->toBe(26100);
    expect($result['credit_net'])->toBe(2900);
});

// ── computeProrata: edge cases with zero amounts ───────────────────────────────

it('handles upgrade from free plan (currentNet = 0)', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(0, 2900, false, $start, $end);

    expect($result['charge_net'])->toBe(1450);
    expect($result['credit_net'])->toBe(0);
});

it('handles downgrade to free plan (newNet = 0)', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    $result = BillingPolicy::computeProrata(2900, 0, false, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(1450);
});

it('handles interval change from free plan', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    // unusedCredit = 0 * 0.5 = 0, netDue = 29000
    $result = BillingPolicy::computeProrata(0, 29000, true, $start, $end);

    expect($result['charge_net'])->toBe(29000);
    expect($result['credit_net'])->toBe(0);
});

it('handles interval change to free plan', function (): void {
    $start = now()->subDays(15)->startOfDay();
    $end = now()->addDays(15)->startOfDay();

    // unusedCredit = 29000 * 0.5 = 14500, netDue = 0 - 14500 = -14500
    $result = BillingPolicy::computeProrata(29000, 0, true, $start, $end);

    expect($result['charge_net'])->toBe(0);
    expect($result['credit_net'])->toBe(14500);
});

// ── computeProrata: rounding ───────────────────────────────────────────────────

it('rounds prorata amounts to whole cents', function (): void {
    // €33 → €50/month, 1/3 remaining (10 of 30 days)
    // diff = 1700, factor = 10/30 = 0.333333
    // charge = round(1700 * 0.333333) = round(566.666) = 567
    $start = now()->subDays(20)->startOfDay();
    $end = now()->addDays(10)->startOfDay();

    $result = BillingPolicy::computeProrata(3300, 5000, false, $start, $end);

    expect($result['charge_net'])->toBeInt();
    expect($result['credit_net'])->toBeInt();
});
