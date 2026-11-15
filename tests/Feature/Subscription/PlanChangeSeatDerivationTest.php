<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * A plan change does not carry seats over.
 *
 * Without an explicit `seats`, it lands on the seats in use or the seats the new plan
 * includes, whichever is more. Carrying the old count across turned the old plan's
 * INCLUDED seats into paid extras on a plan that includes fewer. Whoever wants to keep
 * more passes `seats`. An interval change and a seat/addon change leave the count alone.
 *
 * World catalog: every plan includes two seats and sells more at 5.00; individual tests
 * change that.
 */
beforeEach(fn () => World::boot());

it('derives the seat count in one place', function (): void {
    // plan change: used or included, never the old count
    expect(BillingPolicy::derivedSeats(5, 1, 2, planChanged: true))->toBe(2)
        ->and(BillingPolicy::derivedSeats(5, 4, 2, planChanged: true))->toBe(4)
        ->and(BillingPolicy::derivedSeats(2, 1, 5, planChanged: true))->toBe(5)
        // anything else: the count stays
        ->and(BillingPolicy::derivedSeats(5, 1, 2, planChanged: false))->toBe(5)
        ->and(BillingPolicy::derivedSeats(2, 4, 2, planChanged: false))->toBe(4);
});

it('does not turn the old plan\'s included seats into paid extras', function (): void {
    // Enterprise comes with five seats; Pro with two. One seat is in use.
    config()->set('mollie-billing-plans.plans.enterprise.included_seats', 5);
    $billable = World::subscription('none', 'enterprise', 5, [], used: 1);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'pro', 'monthly');

    $report = World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // Two seats — Pro's own — and Pro's bare price. It used to be five, three of them
    // paid: 65.00 a month instead of 50.00.
    expect($preview['newSeats'])->toBe(2)
        ->and($preview['extraSeatsCharged'])->toBe(0)
        ->and($preview['newPriceNet'])->toBe(5000)
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and(World::recurringNet($billable))->toBe(5000)
        ->and($report['booked'])->toBe($report['previewed'])
        ->and(World::$heldValue)->toBe('60.00');

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(5000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('drops paid extra seats on an upgrade and a downgrade alike, crediting what was paid for them', function (string $target) {
    // Pro with four paid extras (six seats), one in use.
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    $preview = app(PreviewService::class)->previewPlanChange($billable, $target, 'monthly');
    $report = World::change($billable, ['plan_code' => $target, 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    $seatLines = array_values(array_filter($report['booked'], fn (array $l): bool => $l[0] === 'seats'));

    // The four extras are credited for the 21 of 31 days left (2000 × 21/31 = 1355) and
    // not charged again.
    expect($preview['newSeats'])->toBe(2)
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and($seatLines)->toBe([['seats', null, -1355]])
        ->and($report['booked'])->toBe($report['previewed']);

    World::assertNothingOverRefunded($billable);
})->with(['upgrade' => 'enterprise', 'downgrade' => 'starter']);

it('keeps the seats that are in use', function (): void {
    $billable = World::subscription('none', 'pro', 6, [], used: 4);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'starter', 'monthly');
    World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    // Four in use, two included: two paid extras on Starter. 20.00 + 2 × 5.00.
    expect($preview['newSeats'])->toBe(4)
        ->and($preview['extraSeatsCharged'])->toBe(2)
        ->and($billable->getBillingSeatCount())->toBe(4)
        ->and(World::recurringNet($billable))->toBe(3000);
});

it('keeps every seat when the caller asks for it', function (): void {
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    $preview = app(PreviewService::class)->previewUpdate($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 6]);
    World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 6, 'apply_at' => 'immediate']);
    $billable->refresh();

    // 20.00 + 4 × 5.00.
    expect($preview['newSeats'])->toBe(6)
        ->and($billable->getBillingSeatCount())->toBe(6)
        ->and(World::recurringNet($billable))->toBe(4000);
});

it('raises the count to what the new plan includes', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise.included_seats', 5);
    $billable = World::subscription('none', 'pro', 2, [], used: 1);

    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    expect($billable->refresh()->getBillingSeatCount())->toBe(5)
        ->and(World::recurringNet($billable))->toBe(20000);
});

it('leaves the seat count alone on an interval change and on an addon change', function (): void {
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    expect(app(PreviewService::class)->previewUpdate($billable, ['interval' => 'yearly'])['newSeats'])->toBe(6)
        ->and(app(PreviewService::class)->previewAddonChange($billable, ['storage'])['newSeats'])->toBe(6);

    World::change($billable, ['addons' => ['storage']]);
    expect($billable->refresh()->getBillingSeatCount())->toBe(6);

    World::change($billable, ['plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'immediate']);
    expect($billable->refresh()->getBillingSeatCount())->toBe(6)
        // 500.00 + 4 × 50.00 + 90.00 a year.
        ->and(World::recurringNet($billable))->toBe(79000);
});

it('applies the same rule to a change scheduled for the period end', function (): void {
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    $billable->refresh();

    // Stored — and announced to Mollie — with Starter's two seats: 20.00 + 20 %.
    expect($billable->getBillingSubscriptionMeta()['scheduled_change']['seats'])->toBe(2)
        ->and($billable->getBillingSeatCount())->toBe(6)
        ->and(World::$heldValue)->toBe('24.00');

    $renewal = World::renew($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and((int) $renewal->amount_net)->toBe(2000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

it('keeps the seats of a scheduled change when they are asked for', function (): void {
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 6, 'apply_at' => 'end_of_period']);

    // 20.00 + 4 × 5.00, + 20 %.
    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['seats'])->toBe(6)
        ->and(World::$heldValue)->toBe('48.00');

    World::renew($billable);
    expect($billable->refresh()->getBillingSeatCount())->toBe(6);
});

it('falls to the included seats on a plan that sells none, without asking', function (): void {
    // Starter sells no extra seats here.
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.seat_price_net', null);
    $billable = World::subscription('none', 'pro', 6, [], used: 2);

    $preview = app(PreviewService::class)->previewPlanChange($billable, 'starter', 'monthly');
    World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);

    // It used to demand an explicit "drop the extra seats" first; dropping is the rule now.
    expect($preview['errors'])->toBe([])
        ->and($preview['newSeats'])->toBe(2)
        ->and($billable->refresh()->getBillingSeatCount())->toBe(2)
        ->and(World::recurringNet($billable))->toBe(2000);
});

it('still refuses a plan that cannot seat the members in use', function (): void {
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.seat_price_net', null);
    $billable = World::subscription('none', 'pro', 6, [], used: 4);

    expect(array_column(app(PreviewService::class)->previewPlanChange($billable, 'starter', 'monthly')['errors'], 'type'))
        ->toBe(['seats_exceed_plan'])
        ->and(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']))
        ->toThrow(SeatDowngradeRequiredException::class);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSeatCount())->toBe(6);
});

it('refuses explicitly kept seats on a plan that sells none', function (): void {
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.seat_price_net', null);
    $billable = World::subscription('none', 'pro', 6, [], used: 1);

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 6, 'apply_at' => 'immediate']))
        ->toThrow(SeatDowngradeRequiredException::class);
});
