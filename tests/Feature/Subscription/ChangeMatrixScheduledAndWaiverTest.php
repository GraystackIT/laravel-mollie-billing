<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 5 — changes scheduled for the period end, and the cases that only
 * exist under a discount: a granted addon going off and on, a coupon entered while a
 * waiver runs, and a coupon that covers everything.
 */
beforeEach(fn () => World::boot());

dataset('scheduling discounts', [
    'no discount' => 'none',
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    '50 % coupon, last period' => 'pct50-last',
    'waiver until a date, then ends' => 'waiver-date-end',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
]);

/** Gross Mollie should hold for the billable on a target configuration. */
function changeMatrixTargetGross(\GraystackIT\MollieBilling\Testing\TestBillable $billable, array $payload): int
{
    return (int) app(\GraystackIT\MollieBilling\Services\Billing\PreviewService::class)->previewUpdate($billable, $payload)['grossTotal'];
}

it('announces a scheduled change to Mollie, books nothing, and renews as the new plan at the boundary', function (string $discount, string $target) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $collected = World::netCollected($billable);
    $expectedGross = changeMatrixTargetGross($billable, ['plan_code' => $target, 'interval' => 'monthly']);

    $result = app(UpdateSubscription::class)->update($billable, ['plan_code' => $target, 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    $billable->refresh();

    // Scheduled: nothing local moved, nothing was charged or credited — and Mollie
    // already holds the target's recurring amount for the next payment it creates.
    expect($result['scheduledFor'])->toBe('2026-02-01T09:00:00+00:00')
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$payments)->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::netCollected($billable))->toBe($collected)
        ->and(World::heldGross())->toBe($expectedGross);

    // The boundary: the renewal arrives at that amount and is invoiced as the new plan.
    $renewal = World::renew($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe($target)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and((int) $renewal->amount_gross)->toBe($expectedGross)
        ->and((int) $renewal->amount_net)->toBe(World::paidForPeriod($billable))
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment')
        ->and(World::$refunds)->toBe([]);

    // And the period after that renews at the same amount.
    $next = World::renew($billable);
    expect(array_column((array) $next->line_items, 'kind'))->not->toContain('adjustment');
})->with('scheduling discounts')->with(['downgrade' => 'starter', 'upgrade' => 'enterprise']);

it('takes a scheduled change back at Mollie when it is cancelled', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $held = World::$heldValue;

    // Enterprise: its price differs from Pro's in every discount state (under a waiver
    // a cheaper plan is free either way and Mollie's amount would not move).
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    expect(World::$heldValue)->not->toBe($held);

    app(UpdateSubscription::class)->cancelScheduledChange($billable->refresh());

    expect(World::$heldValue)->toBe($held)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    $renewal = World::renew($billable);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('scheduling discounts');

it('follows a rescheduled change at Mollie', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    $expectedGross = changeMatrixTargetGross($billable->refresh(), ['plan_code' => 'enterprise', 'interval' => 'monthly']);
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    expect(World::heldGross())->toBe($expectedGross);

    $renewal = World::renew($billable);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and((int) $renewal->amount_gross)->toBe($expectedGross)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('scheduling discounts');

it('keeps the announcement when a seat is changed while a change is scheduled', function (string $discount) {
    // All seats in use — a plan change carries the seats that are in use.
    $billable = World::subscription($discount, 'pro', 3, ['storage'], used: 3);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    // An immediate seat change PATCHes the CURRENT plan's amount — the stored change has
    // to be re-announced right after.
    World::change($billable, ['seats' => 4]);
    $expectedGross = changeMatrixTargetGross($billable->refresh(), ['plan_code' => 'starter', 'interval' => 'monthly']);

    expect(World::heldGross())->toBe($expectedGross);

    $renewal = World::renew($billable);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('starter')
        // The three seats in use come along; the fourth, bought in between and empty,
        // is not carried across the plan change.
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and((int) $renewal->amount_gross)->toBe($expectedGross)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('scheduling discounts');

it('applies a scheduled change now like an immediate one', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $paid = World::paidForPeriod($billable);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    $this->travelTo(Carbon::parse('2026-01-20 12:00:00', 'UTC'));
    $before = (int) \GraystackIT\MollieBilling\Models\BillingInvoice::query()->max('id');

    app(ScheduleSubscriptionChange::class)->apply($billable->refresh());
    World::settle($billable);
    $billable->refresh();

    $after = World::recurringNet($billable);
    $net = World::sum(World::bookedSince($billable, $before));

    // 12 of 31 days left on 20 Jan.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(abs($net - (int) round(($after - $paid) * 12 / 31)))->toBeLessThanOrEqual(4);

    World::assertNothingOverRefunded($billable);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe($after)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('scheduling discounts');

// ── Only under a waiver ─────────────────────────────────────────────────────

it('neither credits nor charges a granted addon that is switched off and on again', function (): void {
    // The gift includes SSO; storage was bought on top and is the only thing paid.
    $billable = World::subscription('waiver-gift', 'pro', 2, ['storage']);
    $held = World::$heldValue;

    expect(World::recurringNet($billable))->toBe(900);

    $off = World::change($billable, ['addons' => ['storage']]);

    // Nothing was paid for SSO, so nothing comes back — and the recurring amount is the
    // paid extra, as before.
    expect($off['booked'])->toBe([])->and($off['previewed'])->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::recurringNet($billable))->toBe(900)
        ->and(World::$heldValue)->toBe($held);

    $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
    $on = World::change($billable, ['addons' => ['storage', 'sso']]);

    // It is still part of the gift: switching it back on costs nothing, now or at the
    // renewal.
    expect($on['booked'])->toBe([])->and($on['previewed'])->toBe([])
        ->and(World::$payments)->toBe([])
        ->and(World::recurringNet($billable))->toBe(900)
        ->and(World::$heldValue)->toBe($held);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(900)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('charges an upgrade under a waiver only for what lies above the gift', function (): void {
    // Pro (50.00) is free; one extra seat (5.00) is paid — and in use, so it comes along.
    $billable = World::subscription('waiver-periods', 'pro', 3, [], used: 3);

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    // Enterprise 200.00 × 21/31 = 135.48, seat 3.39 again, the waiver's 50.00 × 21/31 =
    // 33.87 off; the seat's 3.39 credited. Nothing is credited for the plan: it was free.
    expect($report['booked'])->toBe(World::normalise([
        ['plan', 'enterprise', 13548],
        ['seats', null, 339],
        ['coupon', $report['booked'][0][1], -3387],
        ['seats', null, -339],
    ]))
        ->and($report['booked'])->toBe($report['previewed'])
        // 200.00 + 5.00 − 50.00.
        ->and(World::recurringNet($billable))->toBe(15500);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(15500)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('charges nothing and credits nothing for a downgrade under a waiver, and keeps the gift to the cheaper plan\'s price', function (): void {
    $billable = World::subscription('waiver-periods', 'pro', 3, [], used: 3);

    $report = World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // Starter (20.00) is covered by the gift; the paid seat is credited and charged
    // again for the same days. The waiver does not spill over onto the seat.
    expect(World::sum($report['booked']))->toBe(0)
        ->and($report['booked'])->toBe($report['previewed'])
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and(World::recurringNet($billable))->toBe(500);

    World::assertNothingOverRefunded($billable);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(500)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('refuses a coupon entered with a change while a waiver runs, and applies nothing', function (array $payload) {
    $billable = World::subscription('waiver-periods', 'pro', 3, ['storage']);
    $meta = $billable->getBillingSubscriptionMeta();
    $held = World::$heldValue;

    app(CouponService::class)->create([
        'code' => 'EXTRA20', 'name' => 'Extra', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    $thrown = null;
    try {
        app(UpdateSubscription::class)->update($billable, $payload + ['coupon_code' => 'EXTRA20']);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    $billable->refresh();

    // Refused as a whole: no half-applied change, no second discount in the one slot.
    expect($thrown)->not->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->toBe($meta)
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage'])
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$payments)->toBe([])
        ->and(World::$refunds)->toBe([])
        ->and(World::$heldValue)->toBe($held);
})->with([
    'seat form' => [['seats' => 4]],
    'addon form' => [['addons' => ['storage', 'sso']]],
    'plan form' => [['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']],
]);

// ── A coupon that covers everything ─────────────────────────────────────────

it('charges a seat added under a 100 % coupon and pulls Mollie back to the regular date', function (): void {
    $billable = World::subscription('full100', 'pro', 2, []);

    expect(World::recurringNet($billable))->toBe(0)
        ->and(ChargeDeferral::until($billable))->not->toBeNull();

    $report = World::change($billable, ['seats' => 3]);
    $billable->refresh();

    // The coupon is locked to the 50.00 it was granted on: the seat is paid, now
    // (5.00 × 21/31) and at every renewal.
    expect($report['booked'])->toBe([['seats', null, 339]])
        ->and($report['booked'])->toBe($report['previewed'])
        ->and(World::recurringNet($billable))->toBe(500)
        ->and(World::$heldValue)->toBe('6.00')
        ->and(str_contains((string) World::$heldStartDate, '2026-02-01'))->toBeTrue();

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(500)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('charges an upgrade under a 100 % coupon only for what the coupon does not cover', function (): void {
    $billable = World::subscription('full100', 'pro', 2, []);

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // 200.00 × 21/31 less the coupon's 50.00 × 21/31; nothing to credit, Pro was free.
    expect(World::sum($report['booked']))->toBe(13548 - 3387)
        ->and($report['booked'])->toBe($report['previewed'])
        ->and(World::recurringNet($billable))->toBe(15000)
        ->and(World::$heldValue)->toBe('180.00');

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(15000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('pays the credits out when the charge lines of a change add up to nothing', function (): void {
    // The executor's own guard. A charge of 0.00 creates no payment, so there is no
    // webhook — and credits handed over as "pending until the charge clears" were never
    // paid out. The composer no longer produces such a set; this pins the executor
    // against any other caller that does.
    $billable = World::subscription('none', 'pro', 2, ['storage']);

    $intent = new \GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent(
        billable: $billable,
        currentPlan: 'pro', newPlan: 'pro',
        currentInterval: 'monthly', newInterval: 'monthly',
        currentSeats: 2, newSeats: 3,
        currentAddons: ['storage' => 1], newAddons: [],
    );

    $lines = app(\GraystackIT\MollieBilling\Services\Billing\ProrataComposer::class)->compose($intent);
    $charge = array_values(array_filter($lines, fn ($l): bool => $l->direction === 'charge'))[0];

    // A discount that covers the seat's charge exactly.
    $lines[] = new \GraystackIT\MollieBilling\Support\ProrataLine(
        originalInvoice: null, originalLineItemIndex: null,
        kind: 'coupon', code: 'COVERS', label: 'Coupon COVERS', quantity: 1,
        amountNet: -$charge->amountNet, vatRate: $charge->vatRate,
        amountVat: -$charge->amountVat, amountGross: -$charge->amountGross,
        periodStart: $charge->periodStart, periodEnd: $charge->periodEnd,
        daysActive: $charge->daysActive, daysRemaining: $charge->daysRemaining,
        isCouponCovered: false, direction: 'charge',
    );

    $result = app(\GraystackIT\MollieBilling\Services\Billing\ProrataExecutor::class)->execute($billable, $intent, $lines);

    // 900 × 21/31 = 610 for the addon that is gone — paid out now, no payment pending.
    expect($result['path'])->toBe('refund')
        ->and(World::$payments)->toBe([])
        ->and($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and(array_map(fn (array $l): array => [$l['kind'], $l['code'], $l['amount_net']], (array) $result['invoice']->line_items))
        ->toBe([['addon', 'storage', -610]]);
});
