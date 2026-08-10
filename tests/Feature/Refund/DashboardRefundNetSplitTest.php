<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * Mollie only ever reports the GROSS it refunded; the net/VAT split is ours.
 * Backing the net out of that gross with one line's VAT rate loses money on
 * every invoice whose lines are not uniformly taxed, and can also land ABOVE
 * what was charged when the header VAT was rounded per line.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');

    // Skip DOMPDF — this file is about the split, not the document.
    $this->app->instance(InvoiceService::class, new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();

            return true;
        }
    });
});

function dashboardBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'dashboard@example.com',
        'name' => 'Dashboard Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

/**
 * @param  list<array<string, mixed>>  $lineItems
 */
function dashboardInvoice(TestBillable $billable, string $paymentId, array $lineItems): BillingInvoice
{
    $net = 0;
    $vat = 0;
    foreach ($lineItems as $line) {
        $net += (int) $line['amount_net'];
        $vat += (int) $line['vat_amount'];
    }

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = $paymentId;
    $invoice->serial_number = 'IN-26'.random_int(100000, 999999);
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = $lineItems;
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

/**
 * Payment stub in the shape RefundHandler reads.
 *
 * @param  list<array{id: string, value: string}>  $refunds
 */
function dashboardPayment(string $paymentId, array $refunds): object
{
    return new class ($paymentId, $refunds)
    {
        /** @param  list<array{id: string, value: string}>  $refundList */
        public function __construct(public string $id, private array $refundList) {}

        /** @return list<object> */
        public function refunds(): array
        {
            return array_map(fn (array $refund): object => (object) [
                'id' => $refund['id'],
                'amount' => (object) ['value' => $refund['value'], 'currency' => 'EUR'],
            ], $this->refundList);
        }
    };
}

/** @return list<array<string, mixed>> */
function dashboardMixedLines(): array
{
    return [
        ['kind' => 'plan', 'code' => 'ent', 'label' => 'Enterprise', 'quantity' => 1, 'unit_price_net' => 3000, 'amount_net' => 3000, 'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600],
        ['kind' => 'addon', 'code' => 'print', 'label' => 'Print Gateway', 'quantity' => 1, 'unit_price_net' => 900, 'amount_net' => 900, 'vat_rate' => 10.0, 'vat_amount' => 90, 'amount_gross' => 990],
    ];
}

function dashboardCreditNote(): BillingInvoice
{
    return BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->firstOrFail();
}

it('splits a full dashboard refund by the line composition, not by line[0] rate', function (): void {
    $billable = dashboardBillable();
    $original = dashboardInvoice($billable, 'tr_dash_mixed', dashboardMixedLines());

    expect((int) $original->amount_gross)->toBe(4590);

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_mixed', [['id' => 're_dash_mixed', 'value' => '45.90']]),
        $billable,
    );

    // line[0]'s 20% over the whole gross gives round(4590 / 1.2) = 3825 net and
    // 676 VAT: 89 cents of money Mollie returned would appear on no credit note,
    // and refunded_net 3825 < 3900 never lets the invoice read as fully refunded.
    $creditNote = dashboardCreditNote();

    expect($creditNote->amount_net)->toBe(-3900)
        ->and($creditNote->amount_vat)->toBe(-690)
        ->and($creditNote->amount_gross)->toBe(-4590)
        ->and((int) $original->fresh()->refunded_net)->toBe(3900)
        ->and($original->fresh()->isFullyRefunded())->toBeTrue();

    $net = 0;
    $vat = 0;
    foreach ((array) $creditNote->line_items as $line) {
        $net += (int) $line['amount_net'];
        $vat += (int) $line['vat_amount'];
    }

    expect($net)->toBe($creditNote->amount_net)
        ->and($vat)->toBe($creditNote->amount_vat);
});

it('keeps every credit line inside its original line when the header VAT was rounded per line', function (): void {
    $billable = dashboardBillable();
    // 550 + 550 @19%: per-line rounding gives header VAT 210 and gross 1310, so
    // round(1310 / 1.19) = 1101 — one cent more net than was ever charged. The
    // surplus used to be allocated onto a credit line exceeding its original.
    $original = dashboardInvoice($billable, 'tr_dash_round', [
        ['kind' => 'plan', 'code' => 'a', 'label' => 'A', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
        ['kind' => 'addon', 'code' => 'b', 'label' => 'B', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
    ]);

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_round', [['id' => 're_dash_round', 'value' => '13.10']]),
        $billable,
    );

    $creditNote = dashboardCreditNote();

    expect($creditNote->amount_net)->toBe(-1100)
        ->and($creditNote->amount_vat)->toBe(-210)
        ->and($creditNote->amount_gross)->toBe(-1310)
        ->and((int) $original->fresh()->refunded_net)->toBe(1100);

    foreach ((array) $creditNote->line_items as $line) {
        $index = (int) $line['parent_line_item_index'];
        expect(abs((int) $line['amount_net']))
            ->toBeLessThanOrEqual((int) $original->line_items[$index]['amount_net']);
    }
});

it('credits at most the gross a partial dashboard refund returned', function (): void {
    $billable = dashboardBillable();
    $original = dashboardInvoice($billable, 'tr_dash_partial', [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190],
    ]);

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_partial', [['id' => 're_dash_partial', 'value' => '5.00']]),
        $billable,
    );

    // The credited gross must land on — never above — the 500 cents Mollie gave
    // back, otherwise the local ledger claims more than the bank returned.
    $creditNote = dashboardCreditNote();

    expect($creditNote->amount_gross)->toBe(-500)
        ->and($creditNote->amount_net)->toBe(-420)
        ->and($creditNote->amount_vat)->toBe(-80)
        ->and((int) $original->fresh()->refunded_net)->toBe(420);
});

it('clamps a refund larger than the invoice to what is still refundable', function (): void {
    $billable = dashboardBillable();
    $original = dashboardInvoice($billable, 'tr_dash_over', [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190],
    ]);

    // The payment covered more than this document (e.g. a one-time order booked
    // against a smaller invoice row).
    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_over', [['id' => 're_dash_over', 'value' => '119.00']]),
        $billable,
    );

    $creditNote = dashboardCreditNote();

    expect($creditNote->amount_net)->toBe(-1000)
        ->and($creditNote->amount_vat)->toBe(-190)
        ->and((int) $original->fresh()->refunded_net)->toBe(1000);
});

it('credits no VAT when the invoice charged none', function (): void {
    $billable = dashboardBillable();
    // Reverse-charged supply: net == gross, so the whole refund is net and no
    // blended rate may sneak tax onto the credit note.
    $original = dashboardInvoice($billable, 'tr_dash_rc', [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 0.0, 'vat_amount' => 0, 'amount_gross' => 1000],
    ]);

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_rc', [['id' => 're_dash_rc', 'value' => '10.00']]),
        $billable,
    );

    $creditNote = dashboardCreditNote();

    expect($creditNote->amount_net)->toBe(-1000)
        ->and($creditNote->amount_vat)->toBe(0)
        ->and($creditNote->amount_gross)->toBe(-1000)
        ->and((int) $original->fresh()->refunded_net)->toBe(1000);
});

it('reads the refund amount at the scale of its currency', function (): void {
    // JPY has no minor unit: a hardcoded ×100 would read ¥500 as ¥50 000 and
    // clamp every refund to the whole invoice.
    expect(InvoiceService::mollieAmountToMinor('500', 'JPY'))->toBe(500)
        ->and(InvoiceService::mollieAmountToMinor('45.90', 'EUR'))->toBe(4590)
        ->and(InvoiceService::mollieAmountToMinor('11.9', 'EUR'))->toBe(1190)
        ->and(InvoiceService::mollieAmountToMinor(null, 'EUR'))->toBe(0);
});

it('stamps the Mollie refund id on every credit line so a re-delivery dedups', function (): void {
    $billable = dashboardBillable();
    dashboardInvoice($billable, 'tr_dash_dedup', dashboardMixedLines());

    $payment = dashboardPayment('tr_dash_dedup', [['id' => 're_dash_dedup', 'value' => '45.90']]);

    app(RefundHandler::class)->handle($payment, $billable);
    app(RefundHandler::class)->handle($payment, $billable);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);

    $lines = (array) dashboardCreditNote()->line_items;

    expect($lines)->toHaveCount(2);
    foreach ($lines as $line) {
        expect($line['mollie_refund_id'])->toBe('re_dash_dedup');
    }
});

it('adds up two successive partial dashboard refunds to the full invoice', function (): void {
    $billable = dashboardBillable();
    $original = dashboardInvoice($billable, 'tr_dash_twice', dashboardMixedLines());

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_twice', [['id' => 're_first', 'value' => '22.95']]),
        $billable,
    );

    app(RefundHandler::class)->handle(
        dashboardPayment('tr_dash_twice', [
            ['id' => 're_first', 'value' => '22.95'],
            ['id' => 're_second', 'value' => '22.95'],
        ]),
        $billable,
    );

    // Two halves of 45.90 must close the invoice out exactly, both in net and in
    // the VAT the credit notes give back.
    expect((int) $original->fresh()->refunded_net)->toBe(3900)
        ->and($original->fresh()->isFullyRefunded())->toBeTrue();

    $vat = 0;
    foreach (BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get() as $note) {
        $vat += abs((int) $note->amount_vat);
    }

    expect($vat)->toBe(690);
});
