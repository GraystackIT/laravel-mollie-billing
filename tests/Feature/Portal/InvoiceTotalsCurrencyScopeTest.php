<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * A billable can hold invoices in more than one currency — a BILLING_CURRENCY change
 * mid-life leaves the historical rows behind. Every figure that adds those rows up is
 * rendered with a single currency symbol, so it may only add up rows carrying that
 * currency: 20.00 GBP plus 10.00 EUR is not "€30.00".
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);

    MollieBilling::authUsing(fn (): bool => true);
});

function multiCurrencyBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'multi@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function multiCurrencyInvoice(
    TestBillable $billable,
    int $gross,
    string $currency,
    InvoiceStatus $status = InvoiceStatus::Paid,
    ?int $mismatchId = null,
): BillingInvoice {
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = 'subscription';
    $invoice->status = $status;
    $invoice->country = 'AT';
    $invoice->currency = $currency;
    $invoice->amount_net = $gross;
    $invoice->amount_vat = 0;
    $invoice->amount_gross = $gross;
    $invoice->refunded_net = 0;
    $invoice->mismatch_id = $mismatchId;
    $invoice->line_items = [];
    $invoice->save();

    return $invoice;
}

it('scopes the invoice summary totals to the configured currency', function (): void {
    $billable = multiCurrencyBillable();
    multiCurrencyInvoice($billable, 1000, 'EUR');
    multiCurrencyInvoice($billable, 2000, 'GBP');

    Livewire::test('mollie-billing::invoices')
        // Paid / spent = the EUR row only.
        ->assertSee('€10.00')
        // 1000 + 2000 rendered as euros.
        ->assertDontSee('€30.00')
        // Per-row amounts keep their own currency.
        ->assertSee('£20.00');
});

it('still counts every invoice regardless of currency', function (): void {
    $billable = multiCurrencyBillable();
    multiCurrencyInvoice($billable, 1000, 'EUR');
    multiCurrencyInvoice($billable, 2000, 'GBP');
    multiCurrencyInvoice($billable, 3000, 'GBP');

    Livewire::test('mollie-billing::invoices')->assertSeeHtml('>3</span>');
});

it('nets refunds against payments only within the configured currency', function (): void {
    $billable = multiCurrencyBillable();
    multiCurrencyInvoice($billable, 5000, 'EUR');
    multiCurrencyInvoice($billable, -2000, 'EUR', InvoiceStatus::Refunded);
    multiCurrencyInvoice($billable, -1000, 'GBP', InvoiceStatus::Refunded);

    Livewire::test('mollie-billing::invoices')
        // spent = 5000 − 2000; credits = 2000. A GBP credit must not reduce either.
        ->assertSee('€30.00')
        ->assertSee('€20.00')
        ->assertDontSee('€40.00');
});

it('groups the mismatch refund total by the currency each invoice was charged in', function (): void {
    $billable = multiCurrencyBillable();

    $mismatch = BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'status' => CountryMismatchStatus::Pending,
    ]);

    multiCurrencyInvoice($billable, 1000, 'EUR', InvoiceStatus::Paid, $mismatch->id);
    multiCurrencyInvoice($billable, 2000, 'GBP', InvoiceStatus::Paid, $mismatch->id);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('2 invoice(s) totalling €10.00 + £20.00 will be refunded')
        ->assertDontSee('€30.00');
});
