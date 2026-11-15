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

// docs/vat-handling.md: "For non-EU countries that are also not declared in
// `additional_countries`, calculate() throws NonEuCountryException" and names "a Swiss IP"
// as the unbillable signal the mismatch selectors must never offer.
//
// `calculate()` decides "EU" with mpociot's `shouldCollectVAT()`, and mpociot's rule table
// is not the EU-27: it also carries CH (8.1 %), IM and MC. With the REAL calculator —
// tests/Feature/Vat/VatCalculationTest.php pins the documented behaviour only against a
// FakeVatCalculator that knows four countries — Switzerland is silently invoiceable at
// 8.1 % with nothing configured.

beforeEach(function (): void {
    Notification::fake();
    config()->set('mollie-billing.vat.seller_country', 'AT');
    config()->set('mollie-billing.additional_countries', []);

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

it('does not price a non-EU country the VAT library happens to know a rate for', function (string $country): void {
    // Against the REAL calculator on purpose: its rule table is what leaked.
    $vat = app(VatCalculationService::class);

    expect($vat->isInvoiceable($country))->toBeFalse();

    expect(fn () => $vat->calculate($country, 10000))->toThrow(NonEuCountryException::class);
})->with(['Switzerland' => ['CH'], 'Isle of Man' => ['IM'], 'Monaco' => ['MC']]);

it('still prices every EU member state from the library, including Greece under either code', function (): void {
    $vat = app(VatCalculationService::class);

    foreach (\GraystackIT\MollieBilling\Support\CountryResolver::euCountryCodes() as $country) {
        expect($vat->isInvoiceable($country))->toBeTrue();
        expect($vat->calculate($country, 10000)['rate'])->toBeGreaterThan(0.0);
    }

    expect($vat->isInvoiceable('EL'))->toBeTrue();
});

it('prices Switzerland once it is declared, at the declared rate', function (): void {
    config()->set('mollie-billing.additional_countries.CH', ['vat_rate' => 0.0, 'name' => 'Switzerland']);

    $result = app(VatCalculationService::class)->calculate('CH', 10000);

    // The configured 0 %, not the 8.1 % the library carries.
    expect($result['vat'])->toBe(0)->and($result['gross'])->toBe(10000);
});

it('refuses to resolve a country mismatch to Switzerland, before any refund goes out', function (): void {
    $billable = new TestBillable;
    $billable->forceFill([
        'name' => 'Acme',
        'email' => 'acme@example.com',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Pending,
    ]);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->serial_number = 'INV-'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->amount_net = 10000;
    $invoice->amount_vat = 2000;
    $invoice->amount_gross = 12000;
    $invoice->mismatch_id = $mismatch->id;
    $invoice->line_items = [[
        'kind' => 'plan', 'label' => 'Plan', 'code' => 'pro', 'quantity' => 1,
        'unit_price_net' => 10000, 'amount_net' => 10000, 'vat_rate' => 20.0,
        'vat_amount' => 2000, 'amount_gross' => 12000,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    expect(fn () => app(CountryMatchService::class)->resolve($billable, $mismatch, 'CH'))
        ->toThrow(NonEuCountryException::class);

    expect((int) $invoice->fresh()->refunded_net)->toBe(0)
        ->and($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Pending)
        ->and($billable->fresh()->billing_country)->toBe('AT');
});
