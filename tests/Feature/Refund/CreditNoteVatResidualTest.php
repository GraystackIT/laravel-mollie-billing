<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\RefundExceedsInvoiceAmountException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * However many partial refunds an invoice is split into, the credit notes may
 * never give back more VAT than it charged: every Mollie refund is posted for
 * the credited GROSS, so once the sum of those grosses passes what the payment
 * collected Mollie rejects the call — and the last legitimate partial refund
 * becomes impossible.
 */

/** Records the gross handed to Mollie instead of calling it. */
final class RecordingRefundInvoiceService extends RefundInvoiceService
{
    /** @var list<int> */
    public static array $grossSentToMollie = [];

    protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
    {
        self::$grossSentToMollie[] = $grossCents;

        return 'rf_'.$paymentId.'_'.count(self::$grossSentToMollie);
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');

    RecordingRefundInvoiceService::$grossSentToMollie = [];

    // Skip DOMPDF — this file is about the numbers, not the document.
    $this->app->instance(InvoiceService::class, new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): void
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();
        }
    });

    $this->app->bind(RefundInvoiceService::class, fn ($app): RefundInvoiceService => new RecordingRefundInvoiceService(
        $app->make(InvoiceService::class),
        $app->make(WalletUsageService::class),
    ));
});

function residualBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'residual@example.com',
        'name' => 'Residual Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

/**
 * @param  list<array<string, mixed>>  $lineItems
 */
function residualInvoice(TestBillable $billable, array $lineItems): BillingInvoice
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

/** VAT given back by every credit note that references $original. */
function residualCreditedVat(BillingInvoice $original): int
{
    $vat = 0;

    foreach (BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get() as $note) {
        foreach ((array) $note->line_items as $line) {
            if ((int) ($line['parent_invoice_id'] ?? 0) === (int) $original->getKey()) {
                $vat += abs((int) ($line['vat_amount'] ?? 0));
            }
        }
    }

    return $vat;
}

it('credits exactly the VAT charged across four partial refunds of one line', function (): void {
    $billable = residualBillable();
    $invoice = residualInvoice($billable, [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 100, 'amount_net' => 100,
        'vat_rate' => 19.0, 'vat_amount' => 19, 'amount_gross' => 119,
    ]]);

    foreach ([25, 25, 25, 25] as $chunk) {
        app(RefundInvoiceService::class)->refundPartially($invoice, $chunk, RefundReasonCode::Goodwill);
    }

    // Rounding every chunk on its own gives 4 × round(4.75) = 20 VAT for 19
    // charged, and 4 × 30 = 120 gross against the 119 Mollie collected — the
    // fourth call is rejected and the refund cannot be completed.
    expect(RecordingRefundInvoiceService::$grossSentToMollie)->toBe([30, 30, 30, 29])
        ->and(array_sum(RecordingRefundInvoiceService::$grossSentToMollie))->toBe(119)
        ->and(residualCreditedVat($invoice))->toBe(19)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(100)
        ->and($invoice->fresh()->isFullyRefunded())->toBeTrue();
});

it('credits exactly the VAT charged across twelve 25-cent chunks', function (): void {
    $billable = residualBillable();
    $invoice = residualInvoice($billable, [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 300, 'amount_net' => 300,
        'vat_rate' => 19.0, 'vat_amount' => 57, 'amount_gross' => 357,
    ]]);

    for ($i = 0; $i < 12; $i++) {
        app(RefundInvoiceService::class)->refundPartially($invoice, 25, RefundReasonCode::Goodwill);
    }

    // Per-chunk rounding books 12 × 5 = 60 VAT for 57 charged.
    expect(array_sum(RecordingRefundInvoiceService::$grossSentToMollie))->toBe(357)
        ->and(residualCreditedVat($invoice))->toBe(57)
        ->and((int) $invoice->fresh()->refunded_net)->toBe(300);
});

it('keeps a discount from swallowing VAT when its rate matches no positive line', function (): void {
    $billable = residualBillable();
    $invoice = residualInvoice($billable, [
        ['kind' => 'plan', 'code' => 'a', 'label' => 'A', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 20.0, 'vat_amount' => 200, 'amount_gross' => 1200],
        ['kind' => 'addon', 'code' => 'b', 'label' => 'B', 'quantity' => 1, 'unit_price_net' => 500, 'amount_net' => 500, 'vat_rate' => 10.0, 'vat_amount' => 50, 'amount_gross' => 550],
        ['kind' => 'coupon', 'code' => null, 'label' => 'Discount', 'quantity' => 1, 'unit_price_net' => -100, 'amount_net' => -100, 'vat_rate' => 0.0, 'vat_amount' => 0, 'amount_gross' => -100],
    ]);

    expect((int) $invoice->amount_net)->toBe(1400)
        ->and((int) $invoice->amount_vat)->toBe(250);

    $creditNote = app(InvoiceService::class)->createCreditNote($invoice, 1400);

    // The 0% discount is folded into the largest positive line. Folding only its
    // net re-taxed the reduced line at 20% → 180 + 50 = 230, leaving 20 cents of
    // charged VAT that no credit note ever returns.
    expect($creditNote->amount_vat)->toBe(-250)
        ->and($creditNote->amount_net)->toBe(-1400)
        ->and($creditNote->amount_gross)->toBe(-1650);

    $net = 0;
    $vat = 0;
    foreach ((array) $creditNote->line_items as $line) {
        $net += (int) $line['amount_net'];
        $vat += (int) $line['vat_amount'];
    }

    expect($net)->toBe($creditNote->amount_net)
        ->and($vat)->toBe($creditNote->amount_vat);
});

it('refuses a credit note whose net exceeds the invoice it credits', function (): void {
    $billable = residualBillable();
    // 550 + 550 @19%: per-line rounding makes the header VAT 210 and the gross
    // 1310, so a net backed out as round(1310 / 1.19) lands at 1101 — one cent
    // above what was ever charged.
    $invoice = residualInvoice($billable, [
        ['kind' => 'plan', 'code' => 'a', 'label' => 'A', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
        ['kind' => 'addon', 'code' => 'b', 'label' => 'B', 'quantity' => 1, 'unit_price_net' => 550, 'amount_net' => 550, 'vat_rate' => 19.0, 'vat_amount' => 105, 'amount_gross' => 655],
    ]);

    expect(fn () => app(InvoiceService::class)->createCreditNote($invoice, 1101))
        ->toThrow(RefundExceedsInvoiceAmountException::class);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0);
});
