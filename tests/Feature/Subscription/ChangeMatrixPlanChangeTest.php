<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 2 — plan changes inside one interval, under every discount state,
 * through Phase 1 (`update()`), the prorata payment's webhook (Phase 2) and on to the
 * next renewal.
 *
 * A plan change credits the whole configuration being left and charges the whole one
 * being entered, so what it costs is
 *
 *   (new recurring amount − what was paid for this period) × 21/31
 *
 * with the running discount inside "new recurring amount". Under a plan waiver that is
 * the point: the plan was paid with 0, so nothing is credited for it, and the waiver
 * keeps taking off what it was given for — an upgrade costs the difference above the
 * gift, a downgrade costs nothing and credits nothing.
 */
beforeEach(fn () => World::boot());

dataset('plan change discounts', [
    'no discount' => 'none',
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    '50 % coupon, last period' => 'pct50-last',
    'waiver until a date, then ends' => 'waiver-date-end',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
]);

dataset('plan changes', [
    // [seats, addons, target plan]
    'upgrade' => [2, [], 'enterprise'],
    'upgrade with a seat and an addon' => [3, ['storage'], 'enterprise'],
    'upgrade with a per-seat addon' => [4, ['crm'], 'enterprise'],
    'downgrade' => [2, [], 'starter'],
    'downgrade with a seat and an addon' => [3, ['storage'], 'starter'],
]);

it('charges the difference to what was paid, patches Mollie and renews at that amount', function (string $discount, int $seats, array $addons, string $target) {
    $billable = World::subscription($discount, 'pro', $seats, $addons);
    $paid = World::paidForPeriod($billable);

    $report = World::change($billable, ['plan_code' => $target, 'interval' => 'monthly', 'apply_at' => 'immediate']);

    $billable->refresh();
    $after = World::recurringNet($billable);
    $expected = (int) round(($after - $paid) * 21 / 31);
    $net = World::sum($report['booked']);

    expect($billable->getBillingSubscriptionPlanCode())->toBe($target)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->toBeNull()
        // Preview == booked.
        ->and($report['booked'])->toBe($report['previewed'])
        // A handful of lines, each rounded on its own.
        ->and(abs($net - $expected))->toBeLessThanOrEqual(4, "booked {$net}; paid {$paid}, new recurring {$after}, over 21/31 → {$expected}");

    World::assertNothingOverRefunded($billable);

    if ($after === 0) {
        // Fully covered: nothing to collect until the discount ends.
        expect($report['preview']['grossTotal'])->toBe(0)
            ->and(ChargeDeferral::until($billable))->not->toBeNull();

        return;
    }

    expect(World::heldGross())->toBe($report['preview']['grossTotal']);

    $renewal = World::renew($billable);

    expect((int) $renewal->amount_gross)->toBe(World::heldGross())
        ->and((int) $renewal->amount_net)->toBe($after)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('plan change discounts')->with('plan changes');

it('leaves plan, Mollie and the books untouched when the prorata payment is declined', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $heldBefore = World::$heldValue;
    $collectedBefore = World::netCollected($billable);

    $report = World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: 'failed');

    $billable->refresh();

    expect($report['settled'])->toBe('failed')
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_plan_change'] ?? null)->toBeNull()
        ->and($report['booked'])->toBe([])
        ->and(World::$heldValue)->toBe($heldBefore)
        ->and(World::$refunds)->toBe([])
        ->and(World::netCollected($billable))->toBe($collectedBefore);

    // The renewal that follows is the old configuration's, to the cent.
    $renewal = World::renew($billable);

    expect(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('plan change discounts');

it('ends at a Local free plan with the unused paid share credited', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    $paid = World::paidForPeriod($billable);

    $report = World::change($billable, ['plan_code' => 'free', 'interval' => 'monthly', 'seats' => 2, 'addons' => [], 'apply_at' => 'immediate']);

    $billable->refresh();
    $credited = -World::sum($report['booked']);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('free')
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value)
        ->and(World::$cancelled)->toBeTrue()
        ->and($report['booked'])->toBe($report['previewed'])
        ->and(abs($credited - (int) round($paid * 21 / 31)))->toBeLessThanOrEqual(3, "credited {$credited} of {$paid} paid");

    World::assertNothingOverRefunded($billable);
})->with('plan change discounts');

it('keeps a paid addon on the free plan: on Mollie, charged, and renewed at its price', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 2, ['storage']);
    $paid = World::paidForPeriod($billable);

    $report = World::change($billable, ['plan_code' => 'free', 'interval' => 'monthly', 'addons' => $billable->getActiveBillingAddonCodes(), 'apply_at' => 'immediate']);

    $billable->refresh();
    $after = World::recurringNet($billable);
    $net = World::sum($report['booked']);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('free')
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value)
        ->and(World::$cancelled)->toBeFalse()
        ->and($report['booked'])->toBe($report['previewed'])
        ->and(abs($net - (int) round(($after - $paid) * 21 / 31)))->toBeLessThanOrEqual(4);

    World::assertNothingOverRefunded($billable);

    if ($after === 0) {
        return;
    }

    expect(World::heldGross())->toBe($report['preview']['grossTotal']);

    $renewal = World::renew($billable);

    expect((int) $renewal->amount_net)->toBe($after)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
})->with('plan change discounts');
