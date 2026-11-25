<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * Two ways the refund books disagreed with the money.
 *
 * A credit note is a tax document: what it states has to be what Mollie returned, and
 * `refunded_net` has to be the sum of what was actually credited. Both invariants were
 * breakable — one by a clamp that only reached the API call, one by a window between
 * persisting a note and stamping its dedup key.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function bookkeepingBillable(): TestBillable
{
    /** @var TestBillable $b */
    return TestBillable::create([
        'name' => 'Books Co',
        'email' => uniqid('books-').'@x.test',
        'billing_country' => 'DE',
    ])->refresh();
}

/** 20.00 net at 19 % → 23.80 gross, one plan line. */
function bookkeepingInvoice(TestBillable $billable, int $net = 2000, int $refundedNet = 0): BillingInvoice
{
    $vat = (int) round($net * 0.19);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_bk_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
        'refunded_net' => $refundedNet,
        'period_start' => BillingTime::nowUtc()->subDays(10),
        'period_end' => BillingTime::nowUtc()->addDays(20),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'total_net' => $net,
            'vat_rate' => 19.0,
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
    ]);
}

function bookkeepingRefundLine(BillingInvoice $original, int $net): ProrataLine
{
    $vat = (int) round($net * 0.19);

    return new ProrataLine(
        originalInvoice: $original,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'pro',
        label: 'Pro (unused)',
        quantity: 1,
        amountNet: -$net,
        vatRate: 19.0,
        amountVat: -$vat,
        amountGross: -($net + $vat),
        periodStart: BillingTime::nowUtc(),
        periodEnd: BillingTime::nowUtc()->addDays(20),
        daysActive: 10,
        daysRemaining: 20,
        isCouponCovered: false,
        direction: 'refund',
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 8a — a clamped refund must book the clamped amount
// ─────────────────────────────────────────────────────────────────────────────

it('books the clamped amount when the refund is capped by what is left', function (): void {
    $billable = bookkeepingBillable();

    // 20.00 net invoice with 12.00 already refunded — 8.00 net (9.52 gross) remains.
    $invoice = bookkeepingInvoice($billable, net: 2000, refundedNet: 1200);

    // Phase 1 of a plan change composed this line days ago, before that other refund.
    $line = bookkeepingRefundLine($invoice, 1450);

    $sent = null;
    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent) {
        $sent = (new ReflectionObject($request))->getProperty('amount')->getValue($request)->value;

        return (object) ['id' => 're_clamped'];
    });

    app(InvoiceService::class)->createRefund($billable, [$line], 'Plan change refund');

    // Mollie was correctly asked for the remaining 9.52 …
    expect($sent)->toBe('9.52');

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    // … and the credit note — a tax document — must say the same. It used to state the
    // uncapped −17.26 while 9.52 moved, and `refunded_net` rose by the full 1450, which
    // could push it past `amount_net` and inflated every later refund decision.
    expect((int) $creditNote->amount_gross)->toBe(-952)
        ->and((int) $creditNote->amount_net)->toBe(-800);

    $invoice->refresh();
    expect((int) $invoice->refunded_net)->toBe(2000)
        ->and((int) $invoice->refunded_net)->toBeLessThanOrEqual((int) $invoice->amount_net)
        ->and($invoice->remainingRefundableNet())->toBe(0);
});

it('records why the refund was reduced', function (): void {
    $billable = bookkeepingBillable();
    $invoice = bookkeepingInvoice($billable, net: 2000, refundedNet: 1200);
    $line = bookkeepingRefundLine($invoice, 1450);

    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_clamped']);

    app(InvoiceService::class)->createRefund($billable, [$line], 'Plan change refund');

    // A log line was the only trace before. The note makes the reduction part of the
    // record, the same way ProrataComposer marks a line it had to cap.
    $note = collect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole()->line_items)
        ->first()['refund_cap_note'] ?? null;

    expect($note)->not->toBeNull()
        ->and($note['uncappedRefundNet'])->toBe(1450)
        ->and($note['cappedRefundNet'])->toBe(800);
});

it('keeps the refund token of a line it caps', function (): void {
    // The token is part of the refund's Mollie idempotency key and what a resumed run finds
    // the line by on the credit note; the capped copy used to get a fresh one.
    $billable = bookkeepingBillable();
    $invoice = bookkeepingInvoice($billable, net: 2000, refundedNet: 1200);
    $line = bookkeepingRefundLine($invoice, 1450);
    $line->refundToken = 'tok-capped';

    $keys = [];
    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (?string $key) use (&$keys) {
        $keys[] = $key;

        return Mollie::getFacadeRoot();
    });
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 're_clamped']);

    app(InvoiceService::class)->createRefund($billable, [$line], 'Plan change refund');

    $item = collect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole()->line_items)->first();

    expect($item['refund_token'] ?? null)->toBe('tok-capped')
        ->and(collect($keys)->filter()->contains(fn (string $k): bool => str_ends_with($k, ':tok-capped')))->toBeTrue();
});

it('leaves an unclamped refund exactly as composed', function (): void {
    $billable = bookkeepingBillable();
    $invoice = bookkeepingInvoice($billable, net: 2000);
    $line = bookkeepingRefundLine($invoice, 1450);

    $sent = null;
    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent) {
        $sent = (new ReflectionObject($request))->getProperty('amount')->getValue($request)->value;

        return (object) ['id' => 're_full'];
    });

    app(InvoiceService::class)->createRefund($billable, [$line], 'Plan change refund');

    // Nothing to cap here — the fix must not disturb the ordinary path.
    expect($sent)->toBe('17.26');

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect((int) $creditNote->amount_net)->toBe(-1450)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(1450)
        ->and(collect($creditNote->line_items)->first()['refund_cap_note'] ?? null)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// 8b — the dedup key must exist before anything can throw
// ─────────────────────────────────────────────────────────────────────────────

it('stamps the refund id before the credit note is committed', function (): void {
    $billable = bookkeepingBillable();
    $invoice = bookkeepingInvoice($billable);

    // Read the row as it is at the moment the event fires — the earliest point an app
    // listener can throw, and the point at which the row is already persisted.
    $stampedAtEvent = null;
    Event::listen(CreditNoteIssued::class, function ($event) use (&$stampedAtEvent): void {
        $stampedAtEvent = collect($event->creditNote->line_items)->first()['mollie_refund_id'] ?? null;
    });

    app(InvoiceService::class)->createCreditNote(
        $invoice,
        1000,
        externalReference: ['key' => 'mollie_refund_id', 'value' => 're_early'],
    );

    // Stamped in a second save, this was still null here: a throwing listener produced a
    // 500, and the re-delivery found no stamp and wrote a SECOND credit note for the same
    // refund — `refunded_net` double-booked, VAT under-declared, later refunds refused.
    expect($stampedAtEvent)->toBe('re_early');
});

it('stamps a chargeback id under its own key', function (): void {
    $billable = bookkeepingBillable();
    $invoice = bookkeepingInvoice($billable);

    $note = app(InvoiceService::class)->createCreditNote(
        $invoice,
        1000,
        externalReference: ['key' => 'mollie_chargeback_id', 'value' => 'chb_early'],
    );

    $line = collect($note->line_items)->first();

    // Separate keys, so a chargeback is never mistaken for a refund of the same value.
    expect($line['mollie_chargeback_id'])->toBe('chb_early')
        ->and($line['mollie_refund_id'])->toBeNull();
});

it('stamps every line of a mixed-rate credit note', function (): void {
    $billable = bookkeepingBillable();

    // Two lines at different rates: the credit note gets one line per credited line, and
    // the dedup check scans them all — a partially stamped note would be re-created.
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_mixed',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 3000,
        'amount_vat' => 450,
        'amount_gross' => 3450,
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
                'unit_price_net' => 2000, 'amount_net' => 2000, 'total_net' => 2000,
                'vat_rate' => 19.0, 'vat_amount' => 380, 'amount_gross' => 2380],
            ['kind' => 'addon', 'code' => 'books', 'label' => 'Books', 'quantity' => 1,
                'unit_price_net' => 1000, 'amount_net' => 1000, 'total_net' => 1000,
                'vat_rate' => 7.0, 'vat_amount' => 70, 'amount_gross' => 1070],
        ],
    ]);

    $note = app(InvoiceService::class)->createCreditNote(
        $invoice,
        3000,
        externalReference: ['key' => 'mollie_refund_id', 'value' => 're_mixed'],
    );

    $stamps = collect($note->line_items)->pluck('mollie_refund_id')->all();

    expect(count($stamps))->toBeGreaterThan(1)
        ->and(array_unique($stamps))->toBe(['re_mixed']);
});
