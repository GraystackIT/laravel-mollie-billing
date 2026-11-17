<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Services\Webhook\ChargebackHandler;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingPolicy;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Livewire;

/**
 * Change matrix, part 9 — the edges of balances: a chargeback over a declined balance, a
 * per-seat addon given back while its balance is out, and balances written before they
 * named their components.
 *
 * AT, 20 %; February 2026 has 28 days.
 */
beforeEach(fn () => World::boot());

/**
 * Starter → Pro on 30 Jan with a slow payment; the renewal of 1 Feb is collected for
 * Starter (20.00 + 20 % = 24.00); on 3 Feb the payment settles and the balance for Pro
 * (30.00 × 26/28 = 27.86) is charged — still open. Clock at 3 Feb 12:00.
 *
 * @return array{0: TestBillable, 1: BillingInvoice, 2: string, 3: string, 4: array<string, mixed>}  billable, renewal, balance id, value, metadata
 */
function balanceEdgeOpenPlanBalance(): array
{
    $billable = World::subscription('none', 'starter', 2, [], at: '2026-01-30 12:00:00');
    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    $renewal = World::renew($billable);
    test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata'][UndercollectionCharge::METADATA_KEY]));
    expect($balance)->toHaveCount(1);

    return [$billable->refresh(), $renewal, (string) $balance->keys()->first(), (string) $balance->first()['value'], (array) $balance->first()['metadata']];
}

/** The bank pulls the renewal back — all of it, or `$grossMinor` of it. */
function balanceEdgeChargeBack(TestBillable $billable, BillingInvoice $renewal, ?int $grossMinor = null): void
{
    $gross = BillingMoney::mollieAmount($grossMinor ?? (int) $renewal->amount_gross, 'EUR');

    $payment = new class((string) $renewal->mollie_payment_id, $gross)
    {
        public object $amount;

        public object $amountChargedBack;

        public function __construct(public string $id, private string $gross)
        {
            $this->amount = (object) ['value' => $gross, 'currency' => 'EUR'];
            $this->amountChargedBack = (object) ['value' => '-'.$gross, 'currency' => 'EUR'];
        }

        /** @return list<object> */
        public function chargebacks(): array
        {
            return [(object) [
                'id' => 'chb_edge_'.$this->id,
                'amount' => (object) ['value' => '-'.$this->gross, 'currency' => 'EUR'],
                'reversedAt' => null,
            ]];
        }
    };

    app(ChargebackHandler::class)->handle($payment, $billable->refresh());
}

/** Number of refunds posted at the test Mollie. */
function balanceEdgeRefundCount(): int
{
    return array_sum(array_map('count', World::$refunds));
}

/** A plan change from there is the reset: Starter for a period from today, nothing credited. */
function balanceEdgeAssertReset(TestBillable $billable): void
{
    expect(BillingPolicy::runningPeriodIsPaid($billable->refresh()))->toBeFalse()
        ->and(BillingPolicy::isPastDueReset($billable, true, false))->toBeTrue();

    $refunds = balanceEdgeRefundCount();
    $report = World::change($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'immediate']);
    $billable->refresh();

    expect($report['preview']['isPastDueReset'])->toBeTrue()
        ->and($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([['plan', 'starter', 2000]])
        ->and($report['charged'])->toBe('24.00')
        // Nothing is credited out of a renewal the bank already took back.
        ->and(balanceEdgeRefundCount())->toBe($refunds)
        ->and($billable->subscription_period_starts_at->toDateString())->toBe('2026-02-10')
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBeNull()
        ->and(array_column(UndercollectionCharge::cases($billable), 'status'))->toBe([UndercollectionCharge::STATUS_CLOSED]);

    World::assertNothingOverRefunded($billable);
}

// ── A chargeback over a declined balance ────────────────────────────────────

it('treats a period whose renewal was charged back as unpaid, when the chargeback follows the declined balance', function (): void {
    [$billable, $renewal, $balanceId, $value, $metadata] = balanceEdgeOpenPlanBalance();

    $this->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'failed', $value, $metadata);
    $since = $billable->refresh()->getBillingSubscriptionMeta()['past_due_since'];

    $this->travelTo(Carbon::parse('2026-02-07 12:00:00', 'UTC'));
    balanceEdgeChargeBack($billable, $renewal);
    $meta = $billable->refresh()->getBillingSubscriptionMeta();

    // One dunning episode: its clock is the first failure's, not restarted.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($meta['past_due_since'])->toBe($since)
        ->and($meta['payment_failure']['reason'])->toBe('chargeback');

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    balanceEdgeAssertReset($billable);

    // The declined balance, collected after all: Pro ran 3–10 Feb; the rest comes back.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'paid', $value, $metadata);
    $kept = (int) BillingInvoice::query()->where('mollie_payment_id', $balanceId)->value('amount_net')
        + (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->where('created_at', '>=', '2026-02-12 00:00:00')->sum('amount_net');

    expect(abs($kept - (int) round(3000 * 7 / 28)))->toBeLessThanOrEqual(1, "kept {$kept}");

    World::assertNothingOverRefunded($billable);
});

it('treats a period whose renewal was charged back as unpaid, when the balance is declined after the chargeback', function (): void {
    [$billable, $renewal, $balanceId, $value, $metadata] = balanceEdgeOpenPlanBalance();

    $this->travelTo(Carbon::parse('2026-02-04 12:00:00', 'UTC'));
    balanceEdgeChargeBack($billable, $renewal);
    $since = $billable->refresh()->getBillingSubscriptionMeta()['past_due_since'];

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($since)->toBe('2026-02-04T12:00:00+00:00');

    // The balance's decline lands on a subscription that is already being dunned: the
    // episode and its clock stay the chargeback's.
    $this->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
    World::deliver($billable, $balanceId, 'failed', $value, $metadata);

    expect($billable->refresh()->getBillingSubscriptionMeta()['past_due_since'])->toBe($since);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    balanceEdgeAssertReset($billable);
});

it('treats a partly charged-back renewal as unpaid too, whichever failure is recorded last', function (string $order): void {
    [$billable, $renewal, $balanceId, $value, $metadata] = balanceEdgeOpenPlanBalance();

    // 10.00 of the 24.00 pulled back: the invoice still has money left to credit, so only
    // the chargeback itself can say the period is not paid.
    if ($order === 'chargeback first') {
        $this->travelTo(Carbon::parse('2026-02-04 12:00:00', 'UTC'));
        balanceEdgeChargeBack($billable, $renewal, 1000);
        $this->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
        World::deliver($billable->refresh(), $balanceId, 'failed', $value, $metadata);
    } else {
        $this->travelTo(Carbon::parse('2026-02-05 12:00:00', 'UTC'));
        World::deliver($billable, $balanceId, 'failed', $value, $metadata);
        $this->travelTo(Carbon::parse('2026-02-07 12:00:00', 'UTC'));
        balanceEdgeChargeBack($billable, $renewal, 1000);
    }

    expect($renewal->refresh()->remainingRefundableNet())->toBeGreaterThan(0);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    balanceEdgeAssertReset($billable);
})->with(['chargeback first', 'balance declined first']);

// ── A per-seat addon given back while its balance is out ────────────────────

it('returns exactly the addon seat\'s share of a balance that arrives after the seat was given back', function (): void {
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    // Pro with three seats (one extra, 5.00) in the debit; CRM switched on after it:
    // 20.00 + 5.00 × 2 charged seats (one free). The balance: 30.00 + 20 %.
    $billable = World::subscription('none', 'pro', 3, [], at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;
    World::change($billable, ['addons' => ['crm']]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_edge_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    $balance = collect(World::$payments)->filter(fn (array $p): bool => isset($p['metadata'][UndercollectionCharge::METADATA_KEY]));
    expect($balance->first()['value'])->toBe('36.00');

    // 10 Feb, the balance still open: back to two seats. The extra seat was paid by the
    // renewal and is credited at once (5.00 × 19/28); CRM's charged seats go from two to
    // one — that seat was not paid yet, so nothing is credited for it now.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable->refresh(), ['seats' => 2]);

    expect($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([['seats', null, -(int) round(500 * 19 / 28)]]);

    // 12 Feb: the balance arrives. Of its addon-seat line (10.00 for two seats) half was
    // given back on 10 Feb: 10.00 × 1/2 × 19/28 comes back, and nothing else.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, (string) $balance->keys()->first(), 'paid', '36.00', (array) $balance->first()['metadata']);

    $notes = BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->where('created_at', '>=', '2026-02-12 00:00:00')->get();
    $lines = $notes->flatMap(fn (BillingInvoice $n): array => array_map(fn (array $l): array => [$l['kind'], $l['code'] ?? null, (int) $l['amount_net']], (array) $n->line_items))->all();
    $share = (int) round(1000 * 0.5 * 19 / 28);

    expect($share)->toBe(339)
        ->and($lines)->toBe([['addon_seats', 'crm', -$share]])
        ->and(BillingMoney::minorFromMollieAmount((string) World::$refunds[(string) $balance->keys()->first()][0]->amount->value, 'EUR'))->toBe($share + (int) round($share * 0.2));

    World::assertNothingOverRefunded($billable);
});

// ── A balance written before it named its components ────────────────────────

/**
 * The state a balance of a seat added after the debit was left in by the previous
 * release: the case holds one `undercollection` line and nothing else, and the renewal's
 * adjustment does not say which line it reduces.
 *
 * @return array{0: TestBillable, 1: BillingInvoice, 2: string, 3: array<string, mixed>}
 */
function balanceEdgeLegacySeatBalance(int $used = 0): array
{
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00', used: $used);
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;
    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_legacy_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    $renewal = BillingInvoice::query()->where('mollie_payment_id', 'tr_legacy_early')->firstOrFail();
    $renewal->forceFill(['line_items' => array_map(function (array $line): array {
        unset($line['reduces']);

        return $line;
    }, (array) $renewal->line_items)])->save();

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $key = (string) array_key_first($meta[UndercollectionCharge::META_KEY]);
    $case = $meta[UndercollectionCharge::META_KEY][$key];
    unset($case['lines'], $case['amount_net'], $case['amount_gross']);
    $case['line'] = [
        'kind' => 'undercollection', 'code' => null, 'undercollection' => true,
        'label' => 'Balance for seats/add-ons added before the renewal '.$renewal->serial_number,
        'quantity' => 1, 'unit_price' => 500, 'unit_price_net' => 500, 'amount_net' => 500,
        'vat_rate' => 20.0, 'vat_amount' => 100, 'amount_gross' => 600,
        'period_start' => '2026-02-01T09:00:00+00:00', 'period_end' => '2026-03-01T09:00:00+00:00',
        'reason' => 'renewal_created_before_change', 'completes_invoice_id' => $renewal->id,
    ];
    $meta[UndercollectionCharge::META_KEY][$key] = $case;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    return [$billable->refresh(), $renewal->refresh(), (string) $case['payment_id'], (array) World::$payments[(string) $case['payment_id']]['metadata']];
}

it('books, shows and credits a balance from before the component lines the way it was written', function (): void {
    [$billable, $renewal, $paymentId, $metadata] = balanceEdgeLegacySeatBalance();

    // Shown to the operator and the customer with its amount while it is open.
    expect(UndercollectionCharge::cases($billable))->toHaveCount(1)
        ->and(UndercollectionCharge::cases($billable)[0]['status'])->toBe(UndercollectionCharge::STATUS_PENDING)
        ->and(UndercollectionCharge::cases($billable)[0]['amount_gross'])->toBe(600)
        ->and(UndercollectionCharge::cases($billable)[0]['amount_net'])->toBe(500)
        ->and(UndercollectionCharge::openCases($billable))->toHaveCount(1);

    World::deliver($billable, $paymentId, 'paid', '6.00', $metadata);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();

    // Booked as it was written: one line, the same amounts.
    expect(array_column((array) $invoice->line_items, 'kind'))->toBe(['undercollection'])
        ->and((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_gross)->toBe(600)
        ->and(UndercollectionCharge::isBalanceInvoice($invoice))->toBeTrue()
        ->and(UndercollectionCharge::cases($billable->refresh())[0]['status'])->toBe(UndercollectionCharge::STATUS_PAID);

    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()));
    MollieBilling::authUsing(fn (): bool => true);
    Livewire::test('mollie-billing::invoices')
        ->assertSee(__('billing::portal.balance_charge.invoice_label'))
        ->assertSee('Balance for seats/add-ons added before the renewal '.$renewal->serial_number);
    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()])
        ->assertSee(__('billing::admin.balance_charges.status.paid'));

    // A seat given back: the old rule, because nothing says the balance is the seat's —
    // the renewal's seat line at the share the adjustment leaves it (5.00 × 50/55),
    // for 19 of 28 days.
    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    $report = World::change($billable->refresh(), ['seats' => 2]);
    $paid = (int) round(500 * 5000 / 5500);

    expect($report['booked'])->toBe($report['previewed'])
        ->and($report['booked'])->toBe([['seats', null, -(int) round($paid * round(19 / 28, 6))]]);

    World::assertNothingOverRefunded($billable);
});

it('returns the unused days of a balance from before the component lines that arrives after a plan change', function (): void {
    [$billable, , $paymentId, $metadata] = balanceEdgeLegacySeatBalance(used: 3);

    $this->travelTo(Carbon::parse('2026-02-10 12:00:00', 'UTC'));
    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate']);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise');

    // The seat balance arrives on 12 Feb. The configuration it was for was left on
    // 10 Feb: 5.00 × 19/28 comes back.
    $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'UTC'));
    World::deliver($billable, $paymentId, 'paid', '6.00', $metadata);

    $returned = (int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->where('created_at', '>=', '2026-02-12 00:00:00')->sum('amount_net');

    expect($returned)->toBe(-(int) round(500 * 19 / 28))
        ->and(BillingMoney::minorFromMollieAmount((string) World::$refunds[$paymentId][0]->amount->value, 'EUR'))->toBe((int) round(500 * 19 / 28) + (int) round(round(500 * 19 / 28) * 0.2));

    World::assertNothingOverRefunded($billable);

    // Delivered again: nothing twice.
    World::deliver($billable, $paymentId, 'paid', '6.00', $metadata);
    expect((int) BillingInvoice::query()->where('billable_id', $billable->getKey())->where('invoice_kind', InvoiceKind::Refund)->where('created_at', '>=', '2026-02-12 00:00:00')->sum('amount_net'))->toBe($returned);
});
