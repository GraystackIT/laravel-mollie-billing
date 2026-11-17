<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * Change matrix, part 8 — the balance of seats and addons states what it is made of.
 *
 * A seat or addon booked after Mollie created the next debit leaves that renewal short;
 * the rest is collected as a balance. That balance used to be one amount of a kind no
 * credit reads, so a seat given back later in the period was credited from the renewal
 * alone — whose seat line carries its share of the `adjustment`: 5.00 × 50/55 × 19/28 =
 * 3.08 for a seat that was paid 5.00, where 5.00 × 19/28 = 3.39 is owed.
 *
 * February 2026: 28 days, the clock at 10 Feb 12:00 leaves 19.
 */
beforeEach(fn () => World::boot());

/**
 * Seats and/or addons added on 31 Jan after Mollie created the February debit, which
 * settles on 1 Feb at the old amount. Returns the billable, the renewal, and the balance
 * payment as [id, value, metadata]. The clock is left at 1 Feb 09:01.
 *
 * @param  array<string, mixed>  $added  the change made on 31 Jan
 * @return array{0: TestBillable, 1: BillingInvoice, 2: array{0: string, 1: string, 2: array<string, mixed>}}
 */
function balanceLinesAddedAfterDebit(array $added, int $seats = 2, array $addons = [], int $used = 0): array
{
    $billable = World::subscription('none', 'pro', $seats, $addons, at: '2026-01-31 12:00:00', used: $used);
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;

    World::change($billable, $added);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_bl_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));
    expect($balance)->toHaveCount(1);

    return [
        $billable->refresh(),
        BillingInvoice::query()->where('mollie_payment_id', 'tr_bl_early')->firstOrFail(),
        [(string) $balance->keys()->first(), (string) $balance->first()['value'], (array) $balance->first()['metadata']],
    ];
}

/** The balance invoice of a payment. */
function balanceLinesInvoice(string $paymentId): BillingInvoice
{
    return BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
}

/** Net refunded at Mollie against one payment. */
function balanceLinesRefundedGross(string $paymentId): int
{
    $gross = 0;

    foreach (World::$refunds[$paymentId] ?? [] as $refund) {
        $gross += BillingMoney::minorFromMollieAmount((string) $refund->amount->value, 'EUR');
    }

    return $gross;
}

it('credits a seat paid by a balance at what was paid for it: 5.00 × 19/28 = 3.39, not 3.08', function (string $country, ?string $vatNumber, float $rate) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;

    [$billable, $renewal, $charge] = balanceLinesAddedAfterDebit(['seats' => 3]);

    // The balance: 5.00 and this customer's VAT — as one amount, as it always was.
    $vat = (int) round(500 * $rate / 100);
    expect($charge[1])->toBe(number_format((500 + $vat) / 100, 2, '.', ''));

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    $invoice = balanceLinesInvoice($charge[0]);
    $line = $invoice->line_items[0];

    // One line, and it says what it is: the seat, for the renewal's period.
    expect(array_column((array) $invoice->line_items, 'kind'))->toBe(['seats'])
        ->and($line['undercollection'])->toBeTrue()
        ->and($line['completes_invoice_id'])->toBe($renewal->id)
        ->and(Carbon::parse($line['period_start'])->toDateString())->toBe('2026-02-01')
        ->and(Carbon::parse($line['period_end'])->toDateString())->toBe('2026-03-01')
        ->and((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_vat)->toBe($vat)
        ->and((int) $invoice->amount_gross)->toBe(500 + $vat)
        ->and(UndercollectionCharge::isBalanceInvoice($invoice))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable, ['seats' => 2]);

    $credit = (int) round(500 * 19 / 28);

    expect($credit)->toBe(339)
        ->and($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([['seats', null, -$credit]])
        // Out of the payment that paid for the seat.
        ->and(balanceLinesRefundedGross($charge[0]))->toBe($credit + (int) round($credit * $rate / 100))
        ->and(balanceLinesRefundedGross('tr_bl_early'))->toBe(0)
        // The seat cost what it costs for the nine days it was held: 5.00 × 9/28 = 1.61.
        ->and(500 - $credit)->toBe((int) round(500 * 9 / 28));

    World::assertNothingOverRefunded($billable);

    // And February as a whole: the plan, and the seat for nine days.
    expect((int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('created_at', '>=', '2026-02-01 00:00:00')->sum('amount_net'))
        ->toBe(5000 + (int) round(500 * 9 / 28));
})->with([
    'AT, 20 %' => ['AT', null, 20.0],
    'DE, 19 %' => ['DE', null, 19.0],
    'DE business, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('states one line per component — seats, addon, addon seats — and collects the same total and VAT as one amount would', function (string $country, ?string $vatNumber, float $rate) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;

    // A third seat, Storage (9.00) and CRM (20.00 + 5.00 per seat beyond the first: two
    // of three seats) on top of Pro: 5.00 + 9.00 + 20.00 + 10.00 = 44.00 short.
    [$billable, $renewal, $charge] = balanceLinesAddedAfterDebit(['seats' => 3, 'addons' => ['storage', 'crm']]);

    $vat = (int) round(4400 * $rate / 100);
    expect($charge[1])->toBe(number_format((4400 + $vat) / 100, 2, '.', ''));

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    $invoice = balanceLinesInvoice($charge[0]);

    $lines = collect((array) $invoice->line_items)
        ->mapWithKeys(fn (array $l): array => [$l['kind'].':'.($l['code'] ?? '') => (int) $l['amount_net']])
        ->all();

    expect($lines)->toEqual(['seats:' => 500, 'addon:storage' => 900, 'addon:crm' => 2000, 'addon_seats:crm' => 1000])
        ->and((int) $invoice->amount_net)->toBe(4400)
        ->and((int) $invoice->amount_vat)->toBe($vat)
        ->and((int) $invoice->amount_gross)->toBe(4400 + $vat)
        // The renewal and its balance together are February at list price.
        ->and((int) $renewal->amount_net + (int) $invoice->amount_net)->toBe(5000 + 4400);

    // Each component comes back at what was paid for it, out of the balance.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable, ['addons' => ['crm']]);

    expect($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([['addon', 'storage', -(int) round(900 * 19 / 28)]]);

    // The plan was paid in full by the renewal — the shortfall was never the plan's.
    $report = World::change($billable->refresh(), ['plan_code' => 'starter', 'interval' => 'monthly', 'seats' => 2, 'addons' => [], 'apply_at' => 'immediate']);
    $planCredit = collect($report['booked'])->first(fn (array $l): bool => $l[0] === 'plan' && $l[1] === 'pro');

    expect($report['booked'])->toBe($report['previewed'])
        ->and($planCredit[2])->toBe(-(int) round(5000 * 19 / 28));

    World::assertNothingOverRefunded($billable);
})->with([
    'AT, 20 %' => ['AT', null, 20.0],
    'DE, 19 %' => ['DE', null, 19.0],
    'DE business, reverse charge' => ['DE', 'DE123456789', 0.0],
]);

it('keeps total and VAT of the balance where rounding per line would add a cent', function (): void {
    World::$country = 'DE';
    $billable = World::subscription('none', 'pro', 2, []);
    $renewal = BillingInvoice::query()->where('billable_id', $billable->getKey())->whereNotNull('mollie_subscription_id')->latest('id')->firstOrFail();

    // 11.00 short, made of two components of 5.50: at 19 % each line rounds to 1.05
    // (2.10), the amount as a whole to 2.09. The balance is 13.09 either way.
    app(UndercollectionCharge::class)->settle($billable, $renewal, 6100, 5000, 'renewal_created_before_change', components: [
        ['kind' => 'seats', 'code' => null, 'quantity' => 1, 'net' => 550, 'label' => 'Seat'],
        ['kind' => 'addon', 'code' => 'storage', 'quantity' => 1, 'net' => 550, 'label' => 'Storage'],
    ]);

    $charge = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata']['undercollection']));
    $single = 1100 + (int) round(1100 * 19 / 100);

    expect($single)->toBe(1309)
        ->and(BillingMoney::grossWithLineVat([550, 550], 19.0))->toBe(1310)
        ->and($charge->first()['value'])->toBe('13.09');

    World::deliver($billable, (string) $charge->keys()->first(), 'paid', '13.09', (array) $charge->first()['metadata']);
    $invoice = balanceLinesInvoice((string) $charge->keys()->first());

    expect(array_column((array) $invoice->line_items, 'kind'))->toBe(['seats', 'addon'])
        ->and((int) $invoice->amount_net)->toBe(1100)
        ->and((int) $invoice->amount_vat)->toBe(209)
        ->and((int) $invoice->amount_gross)->toBe(1309)
        ->and(array_sum(array_column((array) $invoice->line_items, 'amount_gross')))->toBe(1309);

    // Rounding, pinned to exactly one cent: each line rounded on its own would carry 1.05;
    // one of them carries 1.04 so that the VAT of the document is the 2.09 the customer
    // was charged. No line is off by more than that cent.
    $offOwnRounding = array_map(
        fn (array $l): int => (int) $l['vat_amount'] - (int) round((int) $l['amount_net'] * 19 / 100),
        (array) $invoice->line_items,
    );
    sort($offOwnRounding);

    expect($offOwnRounding)->toBe([-1, 0]);
});

it('credits each seat at 5.00 for the days left when the renewal covered one seat and the balance the other', function (): void {
    // Three seats (one extra) in the debit, a fourth added after it: the renewal states
    // two extra seats (10.00) and collected one, the balance pays the other.
    [$billable, $renewal, $charge] = balanceLinesAddedAfterDebit(['seats' => 4], seats: 3);
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    expect($charge[1])->toBe('6.00')
        ->and((int) $renewal->amount_net)->toBe(5500);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));

    // Each seat given back: half out of the renewal, half out of the balance — two lines
    // of 2.50 × 19/28 = 1.70, together what one seat is worth for 19 days (3.39) within
    // a cent of rounding per line.
    foreach ([3, 2] as $seats) {
        $report = World::change($billable->refresh(), ['seats' => $seats]);
        $half = (int) round(250 * 19 / 28);

        expect($report['booked'])->toBe($report['previewed'])
            ->and($report['booked'])->toBe([['seats', null, -$half], ['seats', null, -$half]])
            // Rounding, pinned to exactly one cent: two halves of 2.50 × 19/28 = 1.70 each,
            // where the seat as a whole is 3.39. Each line is rounded on its own, as on the
            // credit note; the cent goes to the customer.
            ->and(2 * $half - (int) round(500 * 19 / 28))->toBe(1)
            ->and(-World::sum($report['booked']) - (int) round(500 * 19 / 28))->toBe(1);
    }

    World::assertNothingOverRefunded($billable);

    // Both seats for nine days, the plan for the month.
    expect(abs((int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('created_at', '>=', '2026-02-01 00:00:00')->sum('amount_net')
        - (5000 + (int) round(1000 * 9 / 28))))->toBeLessThanOrEqual(2);
});

it('credits plan and seat in full on a plan change in such a period, and charges the new plan for the days left', function (): void {
    [$billable, , $charge] = balanceLinesAddedAfterDebit(['seats' => 3], used: 3);
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable->refresh(), ['plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate']);

    $plan = (int) round(5000 * 19 / 28);
    $seat = (int) round(500 * 19 / 28);

    expect($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe(World::normalise([
            ['plan', 'pro', -$plan],
            ['seats', null, -$seat],
            ['plan', 'enterprise', (int) round(20000 * 19 / 28)],
            ['seats', null, $seat],
        ]));

    World::assertNothingOverRefunded($billable);
});

it('credits nothing for a seat whose balance is not paid yet, and returns the unused days when the balance arrives', function (): void {
    [$billable, , $charge] = balanceLinesAddedAfterDebit(['seats' => 3]);

    // 10 Feb, the balance still open: the seat goes. The renewal did not pay for it —
    // its adjustment is the seat's — so there is nothing to credit yet.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable, ['seats' => 2]);

    expect($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([]);

    // 12 Feb: the balance is collected. The seat was held for nine of 28 days; the other
    // nineteen come back at once: 5.00 × 19/28 = 3.39.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    $returned = (int) BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->where('created_at', '>=', '2026-02-12 00:00:00')
        ->sum('amount_net');

    expect($returned)->toBe(-(int) round(500 * 19 / 28))
        ->and(balanceLinesRefundedGross($charge[0]))->toBe((int) round(round(500 * 19 / 28) * 1.2));

    World::assertNothingOverRefunded($billable);

    // Delivered again: nothing twice.
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    expect((int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->sum('amount_net'))->toBe($returned);
});

it('names on the renewal which lines its adjustment reduces, without changing what the renewal states', function (): void {
    [, $renewal] = balanceLinesAddedAfterDebit(['seats' => 3, 'addons' => ['storage']]);

    $adjustment = collect((array) $renewal->line_items)->firstWhere('kind', 'adjustment');

    expect($adjustment['reduces'])->toEqual(['seats' => 500, 'addon:storage' => 900])
        ->and((int) ($adjustment['amount_net'] ?? $adjustment['total_net']))->toBe(-1400)
        ->and((int) $renewal->amount_net)->toBe(5000);
});
