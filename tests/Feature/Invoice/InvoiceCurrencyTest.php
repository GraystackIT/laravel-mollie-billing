<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Mollie requires a refund to be posted in the currency of the original payment,
 * and derived documents (credit notes, corrections) must inherit their parent's
 * currency. The configured currency only applies to fresh charges.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    // The package has since been switched to EUR; historical invoices are USD.
    config()->set('mollie-billing.currency', 'EUR');

    $this->app->bind(InvoiceService::class, fn ($app): InvoiceService => new class ($app->make(VatCalculationService::class), $app->make(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): void
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();
        }
    });
});

function currencyBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'currency@example.com',
        'name' => 'Currency Test',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_cur',
        'mollie_mandate_id' => 'mdt_cur',
        'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
    ])->save();

    return $b;
}

function usdInvoice(TestBillable $billable, string $currency = 'USD'): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_usd_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(1000, 999999);
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = $currency;
    $invoice->amount_net = 1000;
    $invoice->amount_vat = 190;
    $invoice->amount_gross = 1190;
    $invoice->line_items = [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 1000, 'amount_net' => 1000,
        'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        'period_start' => now()->subDays(5)->toIso8601String(),
        'period_end' => now()->addDays(25)->toIso8601String(),
    ]];
    $invoice->period_start = now()->subDays(5);
    $invoice->period_end = now()->addDays(25);
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('normalises a lowercase currency code', function (): void {
    expect(InvoiceService::normalizeCurrency('eur'))->toBe('EUR')
        ->and(InvoiceService::normalizeCurrency(' usd '))->toBe('USD')
        ->and(InvoiceService::normalizeCurrency(null))->toBe('EUR');
});

it('derives the minor-unit scale from the currency', function (): void {
    expect(InvoiceService::molliePrice(2900, 'EUR'))->toBe('29.00')
        ->and(InvoiceService::molliePrice(2900, 'JPY'))->toBe('2900')
        ->and(InvoiceService::molliePrice(2900, 'jpy'))->toBe('2900');
});

it('posts a plan-change refund in the original payment currency', function (): void {
    $b = currencyBillable();
    $original = usdInvoice($b);

    $captured = [];
    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'rf_batch_1'];
    });

    $line = new ProrataLine(
        originalInvoice: $original,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'pro',
        label: 'Pro (refund)',
        quantity: 1,
        amountNet: -1000,
        vatRate: 19.0,
        amountVat: -190,
        amountGross: -1190,
        periodStart: now()->subDays(5),
        periodEnd: now()->addDays(25),
        daysActive: 5,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );

    $creditNote = app(InvoiceService::class)->createRefund($b, [$line], 'Plan change');

    expect($captured)->toHaveCount(1)
        ->and($captured[0])->toBeInstanceOf(CreatePaymentRefundRequest::class);

    $amount = (new ReflectionProperty(CreatePaymentRefundRequest::class, 'amount'))->getValue($captured[0]);

    expect($amount->currency)->toBe('USD')
        ->and($amount->value)->toBe('11.90')
        // The batch credit note inherits the refunded invoice's currency, like country.
        ->and($creditNote?->currency)->toBe('USD');
});

it('posts a service-issued refund in the original payment currency and stores the refund id', function (): void {
    $b = currencyBillable();
    $original = usdInvoice($b);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'rf_service_1'];
    });

    $creditNote = app(RefundInvoiceService::class)->refundFully($original, RefundReasonCode::Goodwill);

    $amount = (new ReflectionProperty(CreatePaymentRefundRequest::class, 'amount'))->getValue($captured[0]);

    expect($amount->currency)->toBe('USD')
        ->and($amount->value)->toBe('11.90')
        ->and($creditNote->currency)->toBe('USD')
        ->and($creditNote->line_items[0]['mollie_refund_id'])->toBe('rf_service_1');
});

it('issues the country-correction charge in the original invoice currency', function (): void {
    $b = currencyBillable();
    $original = usdInvoice($b);

    $captured = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured[] = $request;

        return (object) ['id' => 'tr_correction_1', 'status' => 'open'];
    });

    app(InvoiceService::class)->issueCorrectionCharge($original, $b, 'AT', 42);

    expect($captured[0])->toBeInstanceOf(CreatePaymentRequest::class);

    $amount = (new ReflectionProperty(CreatePaymentRequest::class, 'amount'))->getValue($captured[0]);

    expect($amount->currency)->toBe('USD');
});

it('inherits the currency onto the credit note of a derived document', function (): void {
    $b = currencyBillable();
    $original = usdInvoice($b);

    $creditNote = app(InvoiceService::class)->createCreditNote($original, 1000);

    expect($creditNote->currency)->toBe('USD');
});

it('books a fresh charge in the configured currency', function (): void {
    config()->set('mollie-billing.currency', 'usd');
    $b = currencyBillable();

    $invoice = app(InvoiceService::class)->createInvoice(
        billable: $b,
        kind: InvoiceKind::Subscription,
        molliePaymentId: 'tr_fresh_1',
        mollieSubscriptionId: null,
        lineItems: [[
            'kind' => 'plan', 'label' => 'Pro', 'code' => 'pro', 'quantity' => 1,
            'unit_price_net' => 1000, 'amount_net' => 1000,
            'vat_rate' => 19.0, 'vat_amount' => 190, 'amount_gross' => 1190,
        ]],
    );

    expect($invoice->currency)->toBe('USD');
})->with([null]);

it('pins the PDF currency for an invoice without line items', function (): void {
    $b = currencyBillable();

    $invoice = usdInvoice($b);
    $invoice->line_items = [];
    $invoice->save();

    $svc = new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        public function pdfFor(BillingInvoice $invoice, Billable $billable): \Elegantly\Invoices\Pdf\PdfInvoice
        {
            return $this->buildPdfInvoice($invoice, $billable);
        }
    };

    expect($svc->pdfFor($invoice, $b)->getCurrency())->toBe('USD');
});

it('logs an actionable error instead of a silent null pdf_path for an unusable currency', function (): void {
    $b = currencyBillable();
    $invoice = usdInvoice($b, 'XX');

    $logged = [];
    \Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged[] = [$message, $context];
    });
    \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnNull();

    // Real PDF path (no override) so the currency validation runs.
    app(WalletUsageService::class); // touch the container the same way production does
    $svc = new InvoiceService(app(VatCalculationService::class), app(InvoiceNumberGenerator::class));
    (new ReflectionMethod(InvoiceService::class, 'generateAndStorePdf'))->invoke($svc, $invoice, $b);

    expect($invoice->pdf_path)->toBeNull()
        ->and($logged)->not->toBeEmpty()
        ->and($logged[0][0])->toBe('PDF invoice generation failed')
        ->and($logged[0][1]['currency'])->toBe('XX')
        ->and($logged[0][1]['serial_number'])->toBe($invoice->serial_number);
});
