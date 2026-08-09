<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The Invoices tab refund form. The amount field is the dangerous part: it used to
 * treat "no amount" as "refund everything", which a locale-formatted hint plus a
 * `type="number"` input could trigger by accident.
 */
function refundTabBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'refund-tab@example.com',
        'name' => 'Refund Tab',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function refundTabInvoice(TestBillable $billable, int $net = 123456, ?string $currency = null): BillingInvoice
{
    $vat = (int) round($net * 19 / 100);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = 'subscription';
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->refunded_net = 0;
    if ($currency !== null) {
        $invoice->currency = $currency;
    }
    $invoice->line_items = [];
    $invoice->save();

    return $invoice;
}

/**
 * Records what the component asked the refund service to do without touching Mollie.
 */
function spyRefundService(): object
{
    $spy = new class extends RefundInvoiceService
    {
        /** @var array<int, array{0: string, 1: int|null}> */
        public array $calls = [];

        public function __construct()
        {
            // Deliberately not calling parent::__construct() — nothing here needs
            // the invoice/wallet collaborators.
        }

        public function refundPartially(BillingInvoice $invoice, int $amountNet, RefundReasonCode $reason, ?string $reasonText = null, ?array $lineItems = null): BillingInvoice
        {
            $this->calls[] = ['partial', $amountNet];

            return $invoice;
        }

        public function refundFully(BillingInvoice $invoice, RefundReasonCode $reason, ?string $reasonText = null): BillingInvoice
        {
            $this->calls[] = ['full', null];

            return $invoice;
        }
    };

    app()->instance(RefundInvoiceService::class, $spy);

    return $spy;
}

it('refuses an empty amount instead of refunding the whole invoice', function (): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '')
        ->call('refund')
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect($spy->calls)->toBe([]);
});

it('refuses a whitespace-only amount', function (): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '   ')
        ->call('refund')
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect($spy->calls)->toBe([]);
});

it('accepts the comma-decimal example the hint shows', function (): void {
    $spy = spyRefundService();
    app()->setLocale('de');

    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '10,50')
        ->call('refund')
        ->assertSet('error', null);

    expect($spy->calls)->toBe([['partial', 1050]]);
});

it('refunds the full remaining net only through the dedicated action', function (): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->call('refundFull')
        ->assertSet('error', null);

    expect($spy->calls)->toBe([['full', null]]);
});

it('parses locale grouping separators so a pasted amount round-trips', function (string $input, int $expected): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', $input)
        ->call('refund')
        ->assertSet('error', null);

    expect($spy->calls)->toBe([['partial', $expected]]);
})->with([
    'dot decimal' => ['10.50', 1050],
    'comma decimal' => ['10,50', 1050],
    'de grouping' => ['1.234,56', 123456],
    'en grouping' => ['1,234.56', 123456],
    'nbsp grouping' => ["1\u{00A0}234,56", 123456],
    'fr narrow nbsp grouping' => ["1\u{202F}234,56", 123456],
    'de_CH apostrophe grouping' => ["1\u{2019}234.56", 123456],
    'thin space grouping' => ["1\u{2009}234,56", 123456],
]);

it('rejects scientific notation instead of inflating the amount', function (): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '1e3')
        ->call('refund')
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect($spy->calls)->toBe([]);
});

it('rejects more than two decimals and negative amounts', function (string $input): void {
    $spy = spyRefundService();
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', $input)
        ->call('refund')
        ->assertSet('error', fn (?string $e) => $e !== null);

    expect($spy->calls)->toBe([]);
})->with(['three decimals' => ['10.505'], 'negative' => ['-10.50'], 'letters' => ['abc']]);

it('names the locale separator in the invalid-amount error, matching the hint', function (): void {
    spyRefundService();
    app()->setLocale('de');

    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', 'abc')
        ->call('refund')
        ->assertSet('error', fn (?string $e) => $e !== null && str_contains($e, '10,50'));
});

it('renders table amounts in the invoice currency, not the configured default', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('en');

    $billable = refundTabBillable();
    refundTabInvoice($billable, 100000, 'GBP');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->assertSee('£1,000.00')
        ->assertDontSee('€1,000.00');
});

it('locks billableId and refundInvoiceId against client updates', function (): void {
    $mine = refundTabBillable();
    $invoice = refundTabInvoice($mine);

    $other = new TestBillable;
    $other->forceFill(['email' => 'other@example.com', 'name' => 'Other'])->save();

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $mine->getKey()])
        ->call('openRefund', $invoice->id);

    expect(fn () => $component->set('billableId', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('refundInvoiceId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('clears the pending refund target when the modal is closed from the client', function (): void {
    $billable = refundTabBillable();
    $invoice = refundTabInvoice($billable);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->assertSet('refundInvoiceId', $invoice->id)
        ->set('showRefundModal', false)
        ->assertSet('refundInvoiceId', null)
        ->assertSet('refundAmount', null);
});

it('offers no refund control for an invoice that cannot be refunded', function (): void {
    // The button was shown for anything Paid that is not itself a refund. A saldo-zero
    // plan-switch invoice and a locally booked one (a fully coupon-covered order, a
    // zero-amount audit document) are both Paid and neither can be refunded: the service
    // rejects them for having nothing refundable, or no Mollie payment to refund against.
    // So the operator got a button that only ever produced an error.
    $billable = refundTabBillable();

    $zero = refundTabInvoice($billable, net: 0);

    $local = refundTabInvoice($billable, net: 5000);
    $local->forceFill(['mollie_payment_id' => null])->save();

    $normal = refundTabInvoice($billable, net: 5000);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->assertDontSeeHtml('wire:click="openRefund('.$zero->id.')"')
        ->assertDontSeeHtml('wire:click="openRefund('.$local->id.')"')
        ->assertSeeHtml('wire:click="openRefund('.$normal->id.')"');
});
