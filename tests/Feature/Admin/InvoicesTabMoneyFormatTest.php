<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

function makeInvoicesTabBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'admin-invoices@example.com',
        'name' => 'Invoices Tab',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function makeInvoicesTabInvoice(TestBillable $billable, int $net, ?string $currency = null): BillingInvoice
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
    $invoice->line_items = [[
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price' => $net,
        'unit_price_net' => $net,
        'total_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 19.0,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
    ]];
    $invoice->save();

    return $invoice;
}

it('formats the exceeds-remaining error with currency symbol and locale grouping', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('de');

    $billable = makeInvoicesTabBillable();
    $invoice = makeInvoicesTabInvoice($billable, 123456);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '9999,00')
        ->call('refund')
        ->assertSet('error', fn (?string $error) => $error !== null && str_contains($error, '€1.234,56'));
});

it('uses the invoice currency for the error, not the package default', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('en');

    $billable = makeInvoicesTabBillable();
    $invoice = makeInvoicesTabInvoice($billable, 100000, 'GBP');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '5000.00')
        ->call('refund')
        ->assertSet('error', fn (?string $error) => $error !== null && str_contains($error, '£1,000.00'));
});

it('renders the refund modal amounts through the shared formatter', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
    app()->setLocale('de');

    $billable = makeInvoicesTabBillable();
    $invoice = makeInvoicesTabInvoice($billable, 123456);

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->assertSee('€1.234,56')
        ->assertSee('10,50');
});
