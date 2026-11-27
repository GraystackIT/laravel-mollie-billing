<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * The refund dialog tells the operator how to write the amount — and that instruction is a
 * property of the invoice's currency. On a JPY invoice it said "with up to two decimals"
 * three times (description, hint, refusal) and offered the configured currency's "10.50" as
 * the example; typing exactly that example was refused, by a message that repeated the
 * wrong rule. Found by the browser suite (PortalAdminEdgeTest, gap 52).
 */
function decimalsHintBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => uniqid('decimals-').'@example.com',
        'name' => 'Decimals Co',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function decimalsHintInvoice(TestBillable $billable, int $net, string $currency): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = 'subscription';
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = $currency;
    $invoice->amount_net = $net;
    $invoice->amount_vat = 0;
    $invoice->amount_gross = $net;
    $invoice->refunded_net = 0;
    $invoice->line_items = [[
        'kind' => 'plan', 'label' => 'Plan', 'code' => 'pro', 'quantity' => 1,
        'unit_price' => $net, 'unit_price_net' => $net, 'total_net' => $net, 'amount_net' => $net,
        'vat_rate' => 0.0, 'vat_amount' => 0, 'amount_gross' => $net,
    ]];
    $invoice->save();

    return $invoice;
}

it('asks for a whole number on a JPY invoice and offers an example the parser takes', function (string $configured): void {
    config()->set('mollie-billing.currency', $configured);
    app()->setLocale('en');

    $billable = decimalsHintBillable();
    $invoice = decimalsHintInvoice($billable, 3000, 'JPY');

    $component = Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->assertDontSee('two decimals')
        ->assertSee(__('billing::admin.billables.invoices.modal.description_whole'))
        ->assertSee(__('billing::admin.billables.invoices.modal.hint_whole', [
            'example' => BillingMoney::number(10.5, 'JPY'),
            'remaining' => BillingMoney::format(3000, 'JPY'),
        ]), false);

    // The example the dialog gives is accepted as typed.
    $component->set('refundAmount', BillingMoney::number(10.5, 'JPY'))
        ->set('refundReason', 'goodwill')
        ->call('refund')
        ->assertSet('error', fn (?string $error): bool => $error === null || ! str_contains($error, 'Invalid amount'));
})->with(['configured EUR' => 'EUR', 'configured JPY' => 'JPY']);

it('refuses a decimal yen amount with the whole-number rule, not a two-decimals one', function (): void {
    config()->set('mollie-billing.currency', 'JPY');
    app()->setLocale('en');

    $billable = decimalsHintBillable();
    $invoice = decimalsHintInvoice($billable, 3000, 'JPY');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->set('refundAmount', '500.50')
        ->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.invalid_amount_whole', [
            'example' => BillingMoney::number(10.5, 'JPY'),
        ]));
});

it('names three decimals on a KWD invoice', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    app()->setLocale('en');

    $billable = decimalsHintBillable();
    $invoice = decimalsHintInvoice($billable, 30000, 'KWD');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->assertDontSee('two decimals')
        ->assertSee(__('billing::admin.billables.invoices.modal.description_digits', ['digits' => 3]))
        ->set('refundAmount', '1.2345')
        ->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.invalid_amount_digits', [
            'digits' => 3,
            'example' => BillingMoney::number(10.5, 'KWD'),
        ]));
});

it('keeps the two-decimals wording for a EUR invoice, in English and German', function (string $locale): void {
    config()->set('mollie-billing.currency', 'EUR');
    app()->setLocale($locale);

    $billable = decimalsHintBillable();
    $invoice = decimalsHintInvoice($billable, 3000, 'EUR');

    Livewire::test('mollie-billing::admin.billables.invoices-tab', ['billableId' => $billable->getKey()])
        ->call('openRefund', $invoice->id)
        ->assertSee(__('billing::admin.billables.invoices.modal.description'))
        ->set('refundAmount', 'abc')
        ->call('refund')
        ->assertSet('error', __('billing::admin.billables.invoices.flash.invalid_amount', ['example' => BillingMoney::number(10.5, 'EUR')]));
})->with(['en', 'de']);
