<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.path', 'billing/invoices');
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'Test Company',
        'name' => 'Test Seller',
        'email' => 'seller@test.com',
        'tax_number' => 'DE123456789',
        'address' => [
            'street' => 'Test Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ]);

    // Use a subclass that skips actual PDF generation to avoid DOMPDF dependency in tests.
    $this->app->bind(InvoiceService::class, function ($app): InvoiceService {
        return new class ($app->make(\GraystackIT\MollieBilling\Services\Vat\VatCalculationService::class), $app->make(InvoiceNumberGenerator::class), $app->make(\GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface::class)) extends InvoiceService {
            protected function generateAndStorePdf(BillingInvoice $invoice, \GraystackIT\MollieBilling\Contracts\Billable $billable): void
            {
                // Simulate successful PDF storage without actually generating a PDF.
                $invoice->pdf_disk = 'local';
                $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
                $invoice->save();

                Storage::disk('local')->put($invoice->pdf_path, 'fake-pdf-content');
            }
        };
    });
});

function freshBillableForInvoice(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'invoice@example.com',
        'name' => 'Invoice Test',
        'billing_country' => 'DE',
        'billing_street' => 'Test Street 1',
        'billing_city' => 'Berlin',
        'billing_postal_code' => '10115',
    ])->save();

    return $b;
}

it('creates a BillingInvoice from a payment with the expected VAT', function (): void {
    Event::fake([InvoiceCreated::class]);

    $b = freshBillableForInvoice();
    $payment = (object) ['id' => 'tr_inv_1', 'subscriptionId' => null];
    $lineItems = [[
        'kind' => 'plan',
        'label' => 'Pro',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => 1000,
        'unit_price_net' => 1000,
        'total_net' => 1000,
    ]];

    $invoice = app(InvoiceService::class)->createForPayment($payment, 'subscription', $lineItems, $b);

    expect($invoice)->toBeInstanceOf(BillingInvoice::class);
    expect($invoice->amount_net)->toBe(1000);
    expect($invoice->amount_vat)->toBe(190);
    expect($invoice->amount_gross)->toBe(1190);
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    expect($invoice->serial_number)->not->toBeNull();
    expect($invoice->pdf_disk)->toBe('local');
    expect($invoice->pdf_path)->not->toBeNull();
    expect($invoice->hasPdf())->toBeTrue();

    Event::assertDispatched(InvoiceCreated::class);
});

it('throws LineItemTotalsMismatchException when line totals disagree', function (): void {
    $b = freshBillableForInvoice();
    $payment = (object) ['id' => 'tr_inv_2'];
    $lineItems = [[
        'kind' => 'plan',
        'label' => 'Bad',
        'quantity' => 2,
        'unit_price' => 500,
        'unit_price_net' => 500,
        'total_net' => 999, // wrong: 2 * 500 = 1000, not 999
    ]];

    expect(fn () => app(InvoiceService::class)->createForPayment($payment, 'subscription', $lineItems, $b))
        ->toThrow(LineItemTotalsMismatchException::class);
});

it('credit note uses original VAT rate, not current', function (): void {
    Event::fake([CreditNoteIssued::class]);

    $b = freshBillableForInvoice();

    $original = new BillingInvoice;
    $original->billable_type = $b->getMorphClass();
    $original->billable_id = $b->getKey();
    $original->mollie_payment_id = 'tr_orig_3';
    $original->serial_number = 'IN-260001';
    $original->invoice_kind = 'subscription';
    $original->status = InvoiceStatus::Paid;
    $original->country = 'DE';
    $original->vat_rate = 16.0; // pretend old reduced rate
    $original->currency = 'EUR';
    $original->amount_net = 1000;
    $original->amount_vat = 160;
    $original->amount_gross = 1160;
    $original->line_items = [];
    $original->refunded_net = 0;
    $original->save();

    $cn = app(InvoiceService::class)->createCreditNote($original, 1000);

    expect((float) $cn->vat_rate)->toBe(16.0);
    expect($cn->amount_net)->toBe(-1000);
    expect($cn->amount_vat)->toBe(-160);
    expect($cn->amount_gross)->toBe(-1160);
    expect($cn->parent_invoice_id)->toBe($original->id);
    expect($cn->serial_number)->toStartWith('CR');
    expect($cn->hasPdf())->toBeTrue();

    Event::assertDispatched(CreditNoteIssued::class);
});

it('generates sequential serial numbers', function (): void {
    Event::fake([InvoiceCreated::class]);

    $b = freshBillableForInvoice();

    $lineItems = [[
        'kind' => 'plan', 'label' => 'Pro', 'code' => 'pro',
        'quantity' => 1, 'unit_price' => 1000, 'unit_price_net' => 1000, 'total_net' => 1000,
    ]];

    $first = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_seq_1', 'subscriptionId' => null], 'subscription', $lineItems, $b,
    );
    $second = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_seq_2', 'subscriptionId' => null], 'subscription', $lineItems, $b,
    );

    expect($first->serial_number)->toStartWith('IN');
    expect($second->serial_number)->toStartWith('IN');
    expect($first->serial_number)->not->toBe($second->serial_number);
});

it('uses different prefixes for invoices and credit notes', function (): void {
    $generator = app(InvoiceNumberGenerator::class);

    $invoice = $generator->generate('subscription');
    $credit = $generator->generate('credit_note');

    expect($invoice)->toStartWith('IN');
    expect($credit)->toStartWith('CR');
});

it('invoice has a download URL when PDF exists', function (): void {
    $b = freshBillableForInvoice();
    $payment = (object) ['id' => 'tr_inv_dl', 'subscriptionId' => null];
    $lineItems = [[
        'kind' => 'plan',
        'label' => 'Pro',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => 1000,
        'unit_price_net' => 1000,
        'total_net' => 1000,
    ]];

    $invoice = app(InvoiceService::class)->createForPayment($payment, 'subscription', $lineItems, $b);

    expect($invoice->getDownloadUrl())->toContain('invoices');
    expect($invoice->getDownloadUrl())->toContain('download');
});
