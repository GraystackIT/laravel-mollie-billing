<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * A saldo-zero sidegrade copies its refund lines from the invoices it reverses,
 * so it is a derived document: stamping today's configured currency on it makes
 * old-currency amounts read as the new code after a BILLING_CURRENCY change.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    // Today's configuration — the invoice being refunded predates it.
    config()->set('mollie-billing.currency', 'EUR');

    $this->app->instance(InvoiceService::class, new class (app(VatCalculationService::class), app(InvoiceNumberGenerator::class)) extends InvoiceService
    {
        protected function generateAndStorePdf(BillingInvoice $invoice, Billable $billable): void
        {
            $invoice->pdf_disk = 'local';
            $invoice->pdf_path = 'billing/invoices/test/'.$invoice->serial_number.'.pdf';
            $invoice->save();
        }
    });
});

function switchBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'switch@example.com',
        'name' => 'Switch Test',
        'billing_country' => 'DE',
    ])->save();

    return $b;
}

function switchOriginal(TestBillable $billable, string $currency): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_switch_'.uniqid();
    $invoice->serial_number = 'IN-26'.random_int(100000, 999999);
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
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

function switchLine(string $direction, int $net, ?BillingInvoice $original = null): ProrataLine
{
    $vat = (int) round($net * 19 / 100);

    return new ProrataLine(
        originalInvoice: $original,
        originalLineItemIndex: $original !== null ? 0 : null,
        kind: 'plan',
        code: 'pro',
        label: 'Pro',
        quantity: 1,
        amountNet: $net,
        vatRate: 19.0,
        amountVat: $vat,
        amountGross: $net + $vat,
        periodStart: BillingTime::nowUtc(),
        periodEnd: BillingTime::nowUtc()->addMonth(),
        daysActive: 30,
        daysRemaining: 15,
        isCouponCovered: false,
        direction: $direction,
    );
}

it('inherits the refunded invoices currency on a saldo-zero sidegrade', function (): void {
    $billable = switchBillable();
    $original = switchOriginal($billable, 'USD');

    $invoice = app(InvoiceService::class)->createPlanSwitchInvoice(
        $billable,
        [switchLine('charge', 1000)],
        [switchLine('refund', -1000, $original)],
    );

    // Without the inheritance the refund lines — copied from a USD invoice — are
    // summed and rendered under today's EUR.
    expect($invoice->currency)->toBe('USD')
        ->and($invoice->amount_net)->toBe(0)
        ->and($invoice->amount_gross)->toBe(0);
});

it('falls back to the configured currency when the sidegrade refunds nothing', function (): void {
    $billable = switchBillable();

    $invoice = app(InvoiceService::class)->createPlanSwitchInvoice(
        $billable,
        [switchLine('charge', 1000)],
        [],
    );

    expect($invoice->currency)->toBe('EUR');
});
