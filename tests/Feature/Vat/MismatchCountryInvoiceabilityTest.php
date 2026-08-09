<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\NonEuCountryException;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

// Resolving a mismatch refunds every linked invoice FIRST and only then issues the
// correction charge — and `issueCorrectionOnce()` swallows whatever that throws. A
// country the VAT calculator cannot price therefore left the customer fully refunded,
// never re-charged, and holding a `billing_country` that makes every later checkout and
// renewal throw. Both signals of a mismatch can be such a country: a UK-issued card, a
// Swiss IP.

beforeEach(function (): void {
    Notification::fake();

    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService
        {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
            {
                return 'rf_fake_'.$paymentId;
            }
        };
    });
});

function invoiceabilityBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'name' => 'Acme',
        'email' => 'acme@example.com',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'GB',
        'tax_country_ip' => 'GB',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    return $b;
}

function invoiceabilityInvoice(TestBillable $billable, BillingCountryMismatch $mismatch, int $net = 10000): BillingInvoice
{
    $vat = (int) round($net * 0.20);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->serial_number = 'INV-'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->amount_net = $net;
    $invoice->amount_vat = $vat;
    $invoice->amount_gross = $net + $vat;
    $invoice->mismatch_id = $mismatch->id;
    $invoice->line_items = [[
        'kind' => 'plan',
        'label' => 'Plan',
        'code' => 'pro',
        'quantity' => 1,
        'unit_price_net' => $net,
        'amount_net' => $net,
        'vat_rate' => 20.0,
        'vat_amount' => $vat,
        'amount_gross' => $net + $vat,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('refuses to resolve to a country it cannot invoice, before any refund goes out', function (): void {
    $billable = invoiceabilityBillable();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'GB',
        'tax_country_ip' => 'GB',
        'status' => CountryMismatchStatus::Pending,
    ]);

    $invoice = invoiceabilityInvoice($billable, $mismatch);

    expect(fn () => app(CountryMatchService::class)->resolve($billable, $mismatch, 'GB'))
        ->toThrow(NonEuCountryException::class);

    // Nothing moved: no refund, no credit note, the mismatch is still open and the
    // billable still carries a country the package can price.
    expect((int) $invoice->fresh()->refunded_net)->toBe(0)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Pending)
        ->and($billable->fresh()->billing_country)->toBe('AT');
});

it('accepts a non-EU country once it is configured as an additional country', function (): void {
    // The escape hatch the package already ships for exactly this: a seller who does
    // invoice outside the EU declares the country and its rate.
    config()->set('mollie-billing.additional_countries.GB', ['vat_rate' => 20.0]);

    $billable = invoiceabilityBillable();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'GB',
        'tax_country_ip' => 'GB',
        'status' => CountryMismatchStatus::Pending,
    ]);

    invoiceabilityInvoice($billable, $mismatch);

    app(CountryMatchService::class)->resolve($billable, $mismatch, 'GB');

    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Resolved)
        ->and($billable->fresh()->billing_country)->toBe('GB');
});

it('reports invoiceability for the countries the selectors filter on', function (): void {
    $vat = app(VatCalculationService::class);

    expect($vat->isInvoiceable('AT'))->toBeTrue()
        ->and($vat->isInvoiceable('de'))->toBeTrue()
        ->and($vat->isInvoiceable('GB'))->toBeFalse()
        ->and($vat->isInvoiceable('US'))->toBeFalse();

    config()->set('mollie-billing.additional_countries.GB', ['vat_rate' => 20.0]);

    expect($vat->isInvoiceable('GB'))->toBeTrue();
});
