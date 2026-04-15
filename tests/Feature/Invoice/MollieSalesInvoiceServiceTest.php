<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceCreated;
use GraystackIT\MollieBilling\Exceptions\LineItemTotalsMismatchException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

/** Subclass that bypasses the real Mollie API and records what would have been sent. */
class FakeSalesInvoiceService extends MollieSalesInvoiceService
{
    public static array $sent = [];

    protected function mollieSalesInvoicesEnabled(): bool
    {
        return true;
    }

    protected function createMollieSalesInvoice(array $payload): object
    {
        self::$sent[] = $payload;

        return (object) [
            'id' => 'si_'.uniqid(),
            '_links' => (object) [
                'self' => (object) ['href' => 'https://mollie.test/si/123'],
                'pdf' => (object) ['href' => 'https://mollie.test/pdf/123'],
            ],
        ];
    }
}

beforeEach(function (): void {
    FakeSalesInvoiceService::$sent = [];

    $this->app->bind(MollieSalesInvoiceService::class, function ($app): MollieSalesInvoiceService {
        return new FakeSalesInvoiceService($app->make(VatCalculationService::class));
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

    $invoice = app(MollieSalesInvoiceService::class)->createForPayment($payment, 'subscription', $lineItems, $b);

    expect($invoice)->toBeInstanceOf(BillingInvoice::class);
    expect($invoice->amount_net)->toBe(1000);
    expect($invoice->amount_vat)->toBe(190);
    expect($invoice->amount_gross)->toBe(1190);
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    expect($invoice->mollie_sales_invoice_id)->toStartWith('si_');
    expect($invoice->mollie_pdf_url)->toBe('https://mollie.test/pdf/123');

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

    expect(fn () => app(MollieSalesInvoiceService::class)->createForPayment($payment, 'subscription', $lineItems, $b))
        ->toThrow(LineItemTotalsMismatchException::class);
});

it('credit note uses original VAT rate, not current', function (): void {
    Event::fake([CreditNoteIssued::class]);

    $b = freshBillableForInvoice();

    $original = new BillingInvoice;
    $original->billable_type = $b->getMorphClass();
    $original->billable_id = $b->getKey();
    $original->mollie_payment_id = 'tr_orig_3';
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

    $cn = app(MollieSalesInvoiceService::class)->createCreditNote($original, 1000);

    expect((float) $cn->vat_rate)->toBe(16.0);
    expect($cn->amount_net)->toBe(-1000);
    expect($cn->amount_vat)->toBe(-160);
    expect($cn->amount_gross)->toBe(-1160);
    expect($cn->parent_invoice_id)->toBe($original->id);

    Event::assertDispatched(CreditNoteIssued::class);
});
