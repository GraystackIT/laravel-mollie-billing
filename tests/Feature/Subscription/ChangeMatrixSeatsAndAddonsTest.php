<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 1 — seats and addons on a running subscription, under every
 * discount state, from `UpdateSubscription::update()` to the booked invoice and on to the
 * next renewal.
 *
 * Each cell asserts the same chain:
 *
 *   1. the preview's prorata lines are the lines that were booked;
 *   2. what the change costs is the difference between the two recurring amounts for the
 *      21 of 31 days that are left — the running discount is on both sides of it;
 *   3. Mollie holds the recurring gross the preview quoted;
 *   4. the next renewal is invoiced at exactly that, with no adjustment line;
 *   5. no invoice is credited beyond what it collected.
 *
 * See tests/Support/ChangeMatrixWorld.php for the catalog and the Mollie stand-in.
 */
beforeEach(fn () => World::boot());

dataset('change matrix discounts', [
    'no discount' => 'none',
    '50 % coupon' => 'pct50',
    'fixed coupon' => 'fixed',
    '50 % coupon, last period' => 'pct50-last',
    'waiver until a date, then ends' => 'waiver-date-end',
    'waiver until a date, then paid' => 'waiver-date-paid',
    'waiver for N periods' => 'waiver-periods',
    'waiver with a granted addon' => 'waiver-gift',
    '100 % coupon' => 'full100',
]);

dataset('seat and addon changes', [
    // [seats before, addons before, payload builder]
    'seats up' => [2, [], fn (array $addons): array => ['seats' => 4]],
    'seats down' => [4, [], fn (array $addons): array => ['seats' => 3]],
    'addon on' => [2, [], fn (array $addons): array => ['addons' => [...$addons, 'storage']]],
    'addon off' => [2, ['storage'], fn (array $addons): array => ['addons' => array_values(array_diff($addons, ['storage']))]],
    'per-seat addon on' => [4, [], fn (array $addons): array => ['addons' => [...$addons, 'crm']]],
    'per-seat addon off' => [4, ['crm'], fn (array $addons): array => ['addons' => array_values(array_diff($addons, ['crm']))]],
    'seats up with a per-seat addon' => [2, ['crm'], fn (array $addons): array => ['seats' => 4]],
    'seats down with a per-seat addon' => [4, ['crm'], fn (array $addons): array => ['seats' => 2]],
]);

it('books what it previews, at the difference of the recurring amounts, and renews at what Mollie holds', function (string $discount, int $seats, array $addons, Closure $payload) {
    changeMatrixSeatAddonCell($discount, $seats, $addons, $payload, 'monthly', 21, 31);
})->with('change matrix discounts')->with('seat and addon changes');

it('does the same on a yearly subscription, over 355 of 365 days', function (string $discount, int $seats, array $addons, Closure $payload) {
    changeMatrixSeatAddonCell($discount, $seats, $addons, $payload, 'yearly', 355, 365);
})->with('change matrix discounts')->with('seat and addon changes');

function changeMatrixSeatAddonCell(string $discount, int $seats, array $addons, Closure $payload, string $interval, int $left, int $length): void
{
    $billable = World::subscription($discount, 'pro', $seats, $addons, $interval);
    $before = World::recurringNet($billable);

    $report = World::change($billable, $payload($billable->getActiveBillingAddonCodes()));

    $after = World::recurringNet($billable);
    $delta = $after - $before;
    $expected = (int) round($delta * $left / $length);
    $net = World::sum($report['booked']);

    // 1. Preview == booked.
    expect($report['booked'])->toBe($report['previewed']);

    // 2. The change costs the recurring difference for the days that are left.
    if ($delta >= 0 || in_array($discount, ['none', 'pct50', 'waiver-date-end', 'waiver-date-paid', 'waiver-periods', 'waiver-gift', 'full100'], true)) {
        expect(abs($net - $expected))->toBeLessThanOrEqual(3, "booked {$net}, recurring {$before} → {$after} over {$left}/{$length} is {$expected}");
    } else {
        // A fixed discount (and a discount that has just run out) is spread over the
        // lines it was paid with, so a partial removal credits the paid share — never
        // more than the list difference.
        expect($net)->toBeLessThanOrEqual(0)->toBeGreaterThanOrEqual($expected - 3);
    }

    // 5. Nothing credited beyond what was collected.
    World::assertNothingOverRefunded($billable);

    if ($after === 0) {
        // The discount now covers everything (a waiver with its last paid extra gone).
        // Mollie cannot hold an amount of 0: it keeps the full price and the charge is
        // deferred past the discount — so there is no renewal to invoice.
        expect($report['preview']['grossTotal'])->toBe(0)
            ->and(World::$heldStartDate)->not->toBeNull()
            ->and(ChargeDeferral::until($billable->refresh()))->not->toBeNull();

        return;
    }

    // 3. Mollie holds what the preview quoted as the recurring price.
    expect(World::heldGross())->toBe($report['preview']['grossTotal']);

    // 4. And the next renewal is invoiced at exactly that.
    // Read before the renewal: a waiver that ends inside the period this renewal opens
    // is used up by it, and Mollie is re-priced to the full amount for the one after.
    $held = World::heldGross();
    $renewal = World::renew($billable);

    expect((int) $renewal->amount_gross)->toBe($held)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
}
