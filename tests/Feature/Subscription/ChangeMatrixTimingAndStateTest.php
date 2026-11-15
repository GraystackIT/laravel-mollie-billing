<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 4 — when in the period a change happens, what state the
 * subscription is in, and what a second change in the same period does.
 */
beforeEach(fn () => World::boot());

dataset('timing discounts', [
    'no discount' => 'none',
    '50 % coupon' => 'pct50',
    'waiver for N periods' => 'waiver-periods',
]);

// ── First and last day ──────────────────────────────────────────────────────

it('charges the whole period for a seat added on its first day', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 2, ['storage'], at: '2026-01-01 15:00:00');

    $report = World::change($billable, ['seats' => 3]);

    // 31 of 31 days: the seat's full price.
    expect($report['booked'])->toBe([['seats', null, 500]])
        ->and($report['booked'])->toBe($report['previewed']);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

it('credits the whole paid share for a seat removed on the first day', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage'], at: '2026-01-01 15:00:00');

    $report = World::change($billable, ['seats' => 2]);

    // The seat was paid 5.00 — 2.50 under the 50 % coupon; under a waiver it is an extra
    // and was paid in full.
    expect($report['booked'])->toBe([['seats', null, $discount === 'pct50' ? -250 : -500]])
        ->and($report['booked'])->toBe($report['previewed']);

    World::assertNothingOverRefunded($billable);
})->with('timing discounts');

it('charges one day for a seat added on the last full day', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 2, ['storage'], at: '2026-01-31 12:00:00');

    $report = World::change($billable, ['seats' => 3]);

    // 1 of 31 days: 500 / 31 = 16.
    expect($report['booked'])->toBe([['seats', null, 16]])
        ->and($report['booked'])->toBe($report['previewed']);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

it('charges nothing on the renewal day itself, and the renewal carries the seat', function (string $discount) {
    // 1 Feb, before the renewal has arrived: no day of the period is left.
    $billable = World::subscription($discount, 'pro', 2, ['storage'], at: '2026-02-01 08:00:00');
    $before = World::heldGross();

    $report = World::change($billable, ['seats' => 3]);

    expect($report['booked'])->toBe([])
        ->and($report['previewed'])->toBe([])
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3)
        // 5.00 + 20 % more from the next payment Mollie creates.
        ->and(World::heldGross())->toBe($before + 600);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

// ── Two changes in one period ───────────────────────────────────────────────

it('never credits the same thing twice across several changes in one period', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 2, ['storage']);
    $paidAtStart = World::netCollected($billable);
    $heldAtStart = World::$heldValue;

    $steps = [
        ['2026-01-11 12:00:00', ['seats' => 4]],
        ['2026-01-14 12:00:00', ['addons' => ['storage', 'crm']]],
        ['2026-01-17 12:00:00', ['seats' => 2]],
        ['2026-01-20 12:00:00', ['addons' => ['storage']]],
        ['2026-01-23 12:00:00', ['addons' => []]],
        ['2026-01-26 12:00:00', ['addons' => ['storage']]],
    ];

    foreach ($steps as [$at, $payload]) {
        $this->travelTo(Carbon::parse($at, 'UTC'));
        $report = World::change($billable, $payload);

        expect($report['booked'])->toBe($report['previewed'], "step at {$at}");
        World::assertNothingOverRefunded($billable);
    }

    // Back on the configuration the period started with. Everything that was added in
    // between was used for some days, so the customer has paid MORE than at the start,
    // never less — and Mollie holds the starting amount again.
    expect(World::netCollected($billable))->toBeGreaterThanOrEqual($paidAtStart)
        ->and(World::$heldValue)->toBe($heldAtStart)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage']);

    // Nothing is left to credit for what is no longer there.
    expect(BillingInvoice::currentPeriodLines($billable, 'addon_seats', 'crm'))->toBe([])
        ->and(BillingInvoice::currentPeriodLines($billable, 'addon', 'crm'))->toBe([]);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

it('credits an upgrade bought mid-period by what it bought when the plan goes back', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage'], used: 3);
    $paidAtStart = World::netCollected($billable);
    $heldAtStart = World::$heldValue;

    $up = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    expect($up['booked'])->toBe($up['previewed']);

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    $down = World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    expect($down['booked'])->toBe($down['previewed'])
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        // Nine days on Enterprise were used: the customer is out of pocket for them.
        ->and(World::netCollected($billable))->toBeGreaterThan($paidAtStart)
        ->and(World::$heldValue)->toBe($heldAtStart);

    World::assertNothingOverRefunded($billable);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

// ── Trial ───────────────────────────────────────────────────────────────────

it('keeps seats and addons free during the trial and collects them with the first charge', function (): void {
    $billable = World::trial('pro', 2, []);

    $seats = World::change($billable, ['seats' => 4]);
    $addon = World::change($billable, ['addons' => ['crm']]);

    expect($seats['booked'])->toBe([])->and($seats['previewed'])->toBe([])
        ->and($addon['booked'])->toBe([])->and($addon['previewed'])->toBe([])
        ->and(World::$payments)->toBe([])
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        // 50.00 + 2 × 5.00 + CRM 20.00 + 3 charged seats × 5.00 = 95.00 → 114.00.
        ->and(World::$heldValue)->toBe('114.00')
        ->and($addon['preview']['grossTotal'])->toBe(11400);

    // The trial ends: the first real charge, through the conversion webhook.
    $first = World::renew($billable, '2026-01-15T09:00:00+00:00');
    $lines = array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['quantity'], $l['amount_net']], (array) $first->line_items);

    expect((int) $first->amount_net)->toBe(9500)
        ->and($lines)->toBe([
            ['plan', 'pro', 1, 5000],
            ['seats', null, 2, 1000],
            ['addon', 'crm', 1, 2000],
            ['addon_seats', 'crm', 3, 1500],
        ])
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSeatCount())->toBe(4);
});

it('charges a plan change during the trial and ends the trial with it', function (): void {
    $billable = World::trial('pro', 2, ['storage']);

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    expect($report['booked'])->toBe($report['previewed'])
        ->and(World::sum($report['booked']))->toBeGreaterThan(0)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->trial_ends_at)->toBeNull()
        // 200.00 + 9.00, + 20 %.
        ->and(World::$heldValue)->toBe('250.80')
        // The first recurring charge is one full period after the change, not on the
        // old trial end.
        ->and(World::$heldStartDate)->not->toBeNull();

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

// ── Past due ────────────────────────────────────────────────────────────────

it('resets a past-due subscription with a full first period on a plan change', function (string $discount) {
    $billable = World::failRenewal(World::subscription($discount, 'pro', 3, ['storage']));

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();
    $after = World::recurringNet($billable);

    // Nothing was paid for the period that failed, so nothing is credited; the new
    // configuration is charged in full, at its recurring price.
    expect($report['booked'])->toBe($report['previewed'])
        ->and(World::sum($report['booked']))->toBe($after)
        ->and(World::$refunds)->toBe([])
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBeNull()
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-03')
        ->and(World::heldGross())->toBe($report['preview']['grossTotal'])
        // Moved one period out — Mollie must not retry the failed charge at the new price.
        ->and(World::$heldStartDate)->not->toBeNull();

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('timing discounts');

// Adding seats or a paid addon while past due is refused — PastDueExpansionRefusedTest.
