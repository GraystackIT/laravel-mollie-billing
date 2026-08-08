<?php

declare(strict_types=1);

use Elegantly\Invoices\Pdf\PdfInvoice;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * Header totals, per-line VAT and the PDF must agree: the stored amount_vat /
 * amount_gross are what the customer sees on the rendered document.
 */

/** InvoiceService that skips DOMPDF but exposes the PdfInvoice it would render. */
function totalsInvoiceService(): InvoiceService
{
    return new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): bool
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();

            return true;
        }

        public function pdfFor(BillingInvoice $invoice, Billable $billable): PdfInvoice
        {
            return $this->buildPdfInvoice($invoice, $billable);
        }
    };
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');

    $this->svc = totalsInvoiceService();
    $this->app->instance(InvoiceService::class, $this->svc);
});

function totalsBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'totals@example.com',
        'name' => 'Totals Test',
        'billing_country' => 'DE',
        'billing_street' => 'Test Street 1',
        'billing_city' => 'Berlin',
        'billing_postal_code' => '10115',
    ])->save();

    return $b;
}

function totalsOriginal(TestBillable $billable, array $lineItems, ?string $currency = 'EUR'): BillingInvoice
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
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(1000, 999999);
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = $currency;
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->line_items = $lineItems;
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

function mixedVatLines(): array
{
    return [
        [
            'kind' => 'plan', 'code' => 'ent', 'label' => 'Enterprise', 'quantity' => 1,
            'unit_price_net' => 3000, 'amount_net' => 3000,
            'vat_rate' => 20.0, 'vat_amount' => 600, 'amount_gross' => 3600,
        ],
        [
            'kind' => 'addon', 'code' => 'print', 'label' => 'Print Gateway', 'quantity' => 1,
            'unit_price_net' => 900, 'amount_net' => 900,
            'vat_rate' => 10.0, 'vat_amount' => 90, 'amount_gross' => 990,
        ],
    ];
}

it('credits VAT per line instead of applying line[0] rate to the whole net', function (): void {
    $b = totalsBillable();
    $original = totalsOriginal($b, mixedVatLines());

    expect($original->amount_net)->toBe(3900)
        ->and($original->amount_vat)->toBe(690);

    $creditNote = $this->svc->createCreditNote($original, 3900);

    // line[0] rate (20%) over the full net would credit 780 — 90 cents too much.
    expect($creditNote->amount_vat)->toBe(-690)
        ->and($creditNote->amount_net)->toBe(-3900)
        ->and($creditNote->amount_gross)->toBe(-4590);
});

it('credit-note header equals the sum of its own line items across mixed rates', function (): void {
    $b = totalsBillable();
    $creditNote = $this->svc->createCreditNote(totalsOriginal($b, mixedVatLines()), 3900);

    $lines = (array) $creditNote->line_items;
    expect($lines)->toHaveCount(2);

    $net = 0;
    $vat = 0;
    foreach ($lines as $line) {
        $net += (int) $line['amount_net'];
        $vat += (int) $line['vat_amount'];
    }

    expect($net)->toBe($creditNote->amount_net)
        ->and($vat)->toBe($creditNote->amount_vat)
        ->and((float) $lines[0]['vat_rate'])->toBe(20.0)
        ->and((float) $lines[1]['vat_rate'])->toBe(10.0)
        ->and((int) $lines[0]['parent_line_item_index'])->toBe(0)
        ->and((int) $lines[1]['parent_line_item_index'])->toBe(1);
});

it('allocates a partial credit proportionally across the mixed-rate lines', function (): void {
    $b = totalsBillable();
    $creditNote = $this->svc->createCreditNote(totalsOriginal($b, mixedVatLines()), 1950);

    $lines = (array) $creditNote->line_items;
    $net = 0;
    $vat = 0;
    foreach ($lines as $line) {
        $net += (int) $line['amount_net'];
        $vat += (int) $line['vat_amount'];
    }

    // Half of each line: 1500 @20% = 300, 450 @10% = 45.
    expect($net)->toBe(-1950)
        ->and($vat)->toBe(-345)
        ->and($creditNote->amount_vat)->toBe(-345)
        ->and((int) $lines[0]['amount_net'])->toBe(-1500)
        ->and((int) $lines[1]['amount_net'])->toBe(-450);
});

it('mirrors the original per-line VAT rounding on a full credit', function (): void {
    $b = totalsBillable();
    // Two identical lines: per-line rounding gives 105 + 105 = 210, VAT on the
    // summed net would give 209.
    $original = totalsOriginal($b, [
        ['kind' => 'plan', 'code' => 'a', 'label' => 'A', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
        ['kind' => 'addon', 'code' => 'b', 'label' => 'B', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
    ]);

    $creditNote = $this->svc->createCreditNote($original, 1100);

    expect($creditNote->amount_vat)->toBe(-210)
        ->and($original->amount_vat)->toBe(-1 * $creditNote->amount_vat);
});

it('folds a discount line into the same-rate line so refund tracking stays exact', function (): void {
    $b = totalsBillable();
    $original = totalsOriginal($b, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 4000, 'amount_net' => 4000, 'vat_rate' => 19.0, 'vat_amount' => 760, 'amount_gross' => 4760],
        ['kind' => 'coupon', 'code' => null, 'label' => 'Coupon', 'quantity' => 1, 'unit_price_net' => -500, 'amount_net' => -500, 'vat_rate' => 19.0, 'vat_amount' => -95, 'amount_gross' => -595],
    ]);

    $creditNote = $this->svc->createCreditNote($original, 3500);

    expect((array) $creditNote->line_items)->toHaveCount(1)
        ->and($creditNote->amount_vat)->toBe(-665)
        // derivedRefundedNet() sums abs(amount_net) per refund line — a separate
        // positive discount line would inflate it beyond the credited 3500.
        ->and($original->fresh()->derivedRefundedNet())->toBe(3500);
});

it('keeps the single-line shape and rate for a single-rate invoice', function (): void {
    $b = totalsBillable();
    $original = totalsOriginal($b, [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 16.0, 'vat_amount' => 160, 'amount_gross' => 1160],
    ]);

    $creditNote = $this->svc->createCreditNote($original, 1000);

    expect((array) $creditNote->line_items)->toHaveCount(1)
        ->and((float) $creditNote->line_items[0]['vat_rate'])->toBe(16.0)
        ->and($creditNote->amount_vat)->toBe(-160);
});

it('stores the header VAT as the sum of the per-line VAT', function (): void {
    $b = totalsBillable();

    // 550 @19% twice: per line 105 each = 210. VAT on the summed 1100 is 209.
    $invoice = $this->svc->createForPayment(
        (object) ['id' => 'tr_hdr_1', 'subscriptionId' => null],
        'subscription',
        [
            ['kind' => 'plan', 'label' => 'A', 'code' => 'a', 'quantity' => 1, 'unit_price' => 550, 'unit_price_net' => 550, 'total_net' => 550],
            ['kind' => 'addon', 'label' => 'B', 'code' => 'b', 'quantity' => 1, 'unit_price' => 550, 'unit_price_net' => 550, 'total_net' => 550],
        ],
        $b,
    );

    expect($invoice->amount_net)->toBe(1100)
        ->and($invoice->amount_vat)->toBe(210)
        ->and($invoice->amount_gross)->toBe(1310);

    $pdf = $this->svc->pdfFor($invoice, $b);

    expect($pdf->totalTaxAmount()->getMinorAmount()->toInt())->toBe($invoice->amount_vat)
        ->and($pdf->totalAmount()->getMinorAmount()->toInt())->toBe($invoice->amount_gross);
});

it('counts coupon lines that only carry qty and amount_net toward the header', function (): void {
    $b = totalsBillable();

    // Shape emitted by SubscriptionPaymentHandler for coupon discounts.
    $invoice = $this->svc->createForPayment(
        (object) ['id' => 'tr_hdr_2', 'subscriptionId' => null],
        'subscription',
        [
            ['kind' => 'plan', 'label' => 'Pro', 'code' => 'pro', 'quantity' => 1, 'unit_price' => 1000, 'unit_price_net' => 1000, 'total_net' => 1000],
            ['kind' => 'coupon', 'description' => 'Coupon discount', 'qty' => 1, 'unit_price_net' => -300, 'amount_net' => -300, 'vat_rate' => 19.0, 'vat_amount' => -57, 'amount_gross' => -357],
        ],
        $b,
    );

    expect($invoice->amount_net)->toBe(700)
        ->and($invoice->amount_vat)->toBe(133)
        ->and($invoice->amount_gross)->toBe(833);

    $pdf = $this->svc->pdfFor($invoice, $b);

    expect($pdf->subTotalAmount()->getMinorAmount()->toInt())->toBe($invoice->amount_net)
        ->and($pdf->totalAmount()->getMinorAmount()->toInt())->toBe($invoice->amount_gross);
});

it('still detects a genuine divergence between quantity x unit_price and the line net', function (): void {
    $b = totalsBillable();

    expect(fn () => $this->svc->createForPayment(
        (object) ['id' => 'tr_hdr_3'],
        'subscription',
        [['kind' => 'plan', 'label' => 'Bad', 'qty' => 2, 'unit_price' => 500, 'amount_net' => 999]],
        $b,
    ))->toThrow(\GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException::class);
});

it('renders a pro-rata line so the document total equals the stored amount', function (): void {
    $b = totalsBillable();
    // 3 extra seats @500 with factor 0.5667 → 850 net, unit price rounds to 283.
    $original = totalsOriginal($b, [[
        'kind' => 'seats', 'code' => null, 'label' => '3 extra seats (pro-rata)',
        'quantity' => 3, 'unit_price_net' => 283, 'amount_net' => 850,
        'vat_rate' => 19.0, 'vat_amount' => 162, 'amount_gross' => 1012,
    ]]);

    $pdf = $this->svc->pdfFor($original, $b);

    // 3 × 283 = 849 → VAT 161 → total 1010, two cents below what was charged.
    expect($pdf->subTotalAmount()->getMinorAmount()->toInt())->toBe(850)
        ->and($pdf->totalTaxAmount()->getMinorAmount()->toInt())->toBe(162)
        ->and($pdf->totalAmount()->getMinorAmount()->toInt())->toBe($original->amount_gross);
});

it('keeps quantity x unit_price rendering when the two agree', function (): void {
    $b = totalsBillable();
    $original = totalsOriginal($b, [[
        'kind' => 'seats', 'code' => null, 'label' => '3 extra seats',
        'quantity' => 3, 'unit_price_net' => 500, 'amount_net' => 1500,
        'vat_rate' => 19.0, 'vat_amount' => 285, 'amount_gross' => 1785,
    ]]);

    $pdf = $this->svc->pdfFor($original, $b);

    expect($pdf->items[0]->quantity)->toBe(3)
        ->and($pdf->subTotalAmount()->getMinorAmount()->toInt())->toBe(1500);
});
