<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 3 — interval changes, both directions.
 *
 * An interval change opens a full new period: the new configuration is charged in full
 * (with the running discount, as its first renewal would be), the unused share of what was
 * paid for the old period is credited, and Mollie is moved to the new cadence.
 *
 * Under a plan waiver it is refused — see
 * ValidateSubscriptionChange::assertIntervalChangeAllowed().
 */
beforeEach(fn () => World::boot());

dataset('interval change discounts', [
    'no discount' => 'none',
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    '50 % coupon, last period' => 'pct50-last',
]);

dataset('interval changes', [
    // [from, to, days left of the old period on 11 Jan, its length]
    'monthly to yearly' => ['monthly', 'yearly', 21, 31],
    'yearly to monthly' => ['yearly', 'monthly', 355, 365],
]);

it('charges a full new period, credits the old one\'s unused paid share and renews on the new cadence', function (string $discount, string $from, string $to, int $left, int $length) {
    $billable = World::subscription($discount, 'pro', 3, ['storage', 'crm'], $from);
    $paid = World::paidForPeriod($billable);

    // What the first period of the new interval costs: its list price less the discount
    // as it runs TODAY — the charge is made today.
    $list = SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, 'pro', $to, 3, ['storage', 'crm']);
    $firstPeriod = $list - app(CouponService::class)->computeMarkerDiscount($billable, $list, [
        'plan_code' => 'pro', 'interval' => $to, 'addon_codes' => ['storage', 'crm'], 'seats' => 3,
    ]);

    $report = World::change($billable, ['plan_code' => 'pro', 'interval' => $to, 'apply_at' => 'immediate']);

    $billable->refresh();
    // What Mollie collects when that period ends — the discount counts only if it is
    // still running then.
    $after = World::recurringNet($billable);
    $charged = World::sum($report['booked'], '+') + World::sum(array_values(array_filter($report['booked'], fn (array $l): bool => $l[0] === 'coupon')));
    $credited = -World::sum(array_values(array_filter($report['booked'], fn (array $l): bool => $l[2] < 0 && $l[0] !== 'coupon')));

    expect($billable->getBillingSubscriptionInterval())->toBe($to)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and($report['booked'])->toBe($report['previewed'])
        // The first period of the new interval, at its recurring price.
        ->and($charged)->toBe($firstPeriod)
        ->and(abs($credited - (int) round($paid * $left / $length)))->toBeLessThanOrEqual(5, "credited {$credited} of {$paid} paid, {$left}/{$length} left")
        ->and(World::heldGross())->toBe($report['preview']['grossTotal'])
        // Mollie's schedule moved with it.
        ->and(World::$heldStartDate)->not->toBeNull()
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-01-11');

    World::assertNothingOverRefunded($billable);

    $renewal = World::renew($billable);

    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and((int) $renewal->amount_net)->toBe($after)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('interval change discounts')->with('interval changes');

it('refuses an interval change while a plan waiver runs, immediately and scheduled', function (string $discount, string $applyAt) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $held = World::$heldValue;
    $meta = $billable->getBillingSubscriptionMeta();

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => $applyAt]))
        ->toThrow(InvalidSubscriptionStateException::class, 'free of charge');

    $billable->refresh();

    // Nothing moved: no charge, no PATCH, no stored change, the waiver as it was.
    expect($billable->getBillingSubscriptionInterval())->toBe('monthly')
        ->and($billable->getBillingSubscriptionMeta())->toBe($meta)
        ->and(World::$payments)->toBe([])
        ->and(World::$heldValue)->toBe($held)
        ->and(app(PreviewService::class)->previewUpdate($billable, ['interval' => 'yearly'])['errors'])
        ->toContain(['type' => 'interval_change_under_waiver']);
})->with([
    'waiver until a date, then ends' => 'waiver-date-end',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
])->with(['immediate', 'end_of_period']);

it('does not carry a coupon that ends before the next yearly charge into the amount Mollie holds', function (): void {
    // 50 % for twelve monthly periods, one of them used. Switching to yearly on 11 Jan
    // 2026 puts the next charge on 11 Jan 2027 — the coupon is over by November.
    $billable = World::subscription('pct50', 'pro', 2, []);

    $report = World::change($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // The year bought today is discounted like a renewal today would be: 500.00 less
    // 50 % of the 50.00 the coupon was granted on.
    $charged = array_sum(array_map(fn (array $l): int => $l[2], array_filter($report['booked'], fn (array $l): bool => $l[2] > 0 || $l[0] === 'coupon')));

    expect($charged)->toBe(50000 - 2500)
        // The renewal a year from now is not: 500.00 + 20 %.
        ->and(World::$heldValue)->toBe('600.00')
        ->and($report['preview']['grossTotal'])->toBe(60000)
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null)->toBeNull();
});
