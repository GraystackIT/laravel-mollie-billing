<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;

/**
 * An invoice records money Mollie has already collected, so its currency comes from
 * the payment — not from today's config. `BILLING_CURRENCY` can change between the
 * charge being created and its webhook arriving, and a document labelled with a
 * currency the customer was never charged in cannot be refunded: Mollie rejects a
 * refund whose currency differs from the payment's.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    // The package has been switched to EUR since the payments below were created.
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

function paymentCurrencyBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => uniqid('paycur-').'@x.test',
        'name' => 'Payment Currency',
        'billing_country' => 'DE',
        'mollie_customer_id' => 'cst_paycur',
    ])->save();

    return $b;
}

function paymentIn(string $currency, string $value, string $id): object
{
    return (object) [
        'id' => $id,
        'status' => 'paid',
        'amount' => (object) ['value' => $value, 'currency' => $currency],
        'subscriptionId' => null,
    ];
}

it('books createForPayment in the payment currency, not the configured one', function (): void {
    $billable = paymentCurrencyBillable();

    $invoice = app(InvoiceService::class)->createForPayment(
        paymentIn('USD', '11.90', 'tr_paycur_usd'),
        'subscription',
        [['kind' => 'plan', 'label' => 'Plan', 'quantity' => 1, 'unit_price_net' => 1000, 'total_net' => 1000]],
        $billable,
    );

    expect($invoice->currency)->toBe('USD');
});

it('books createInvoice in the currency the caller passes', function (): void {
    $billable = paymentCurrencyBillable();

    $invoice = app(InvoiceService::class)->createInvoice(
        billable: $billable,
        kind: InvoiceKind::Subscription,
        molliePaymentId: 'tr_paycur_prorata',
        mollieSubscriptionId: null,
        lineItems: [[
            'kind' => 'plan',
            'label' => 'Plan',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 19.0,
            'vat_amount' => 190,
            'amount_gross' => 1190,
        ]],
        currency: 'USD',
    );

    expect($invoice->currency)->toBe('USD');
});

it('falls back to the configured currency for a purely local document', function (): void {
    // The zero-amount audit invoice for a fully coupon-covered order: no payment, so
    // no currency to inherit, and it was priced just now.
    $billable = paymentCurrencyBillable();

    $invoice = app(InvoiceService::class)->createInvoice(
        billable: $billable,
        kind: InvoiceKind::OneTimeOrder,
        molliePaymentId: null,
        mollieSubscriptionId: null,
        lineItems: [[
            'kind' => 'product',
            'label' => 'Free',
            'quantity' => 1,
            'unit_price_net' => 0,
            'amount_net' => 0,
            'vat_rate' => 0.0,
            'vat_amount' => 0,
            'amount_gross' => 0,
        ]],
    );

    expect($invoice->currency)->toBe('EUR');
});

it('normalises a lowercase payment currency', function (): void {
    $billable = paymentCurrencyBillable();

    $invoice = app(InvoiceService::class)->createForPayment(
        paymentIn('usd', '11.90', 'tr_paycur_lower'),
        'subscription',
        [['kind' => 'plan', 'label' => 'Plan', 'quantity' => 1, 'unit_price_net' => 1000, 'total_net' => 1000]],
        $billable,
    );

    expect($invoice->currency)->toBe('USD');
});

it('falls back to the configured currency when the payment carries none', function (): void {
    $billable = paymentCurrencyBillable();

    $invoice = app(InvoiceService::class)->createForPayment(
        (object) ['id' => 'tr_paycur_none', 'status' => 'paid', 'amount' => (object) ['value' => '11.90'], 'subscriptionId' => null],
        'subscription',
        [['kind' => 'plan', 'label' => 'Plan', 'quantity' => 1, 'unit_price_net' => 1000, 'total_net' => 1000]],
        $billable,
    );

    expect($invoice->currency)->toBe('EUR');
});
