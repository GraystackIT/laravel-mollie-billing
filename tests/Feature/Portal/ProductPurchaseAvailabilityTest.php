<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/**
 * `purchase($productCode)` takes the code from the client, so the `onetimeonly` filtering
 * `availableBillingProducts()` does for the card list is not a guard — the card is gone
 * from the page but the action still accepted the code, and nothing downstream re-checks
 * (StartOneTimeOrderCheckout knows the flag but never consults it).
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
    config()->set('mollie-billing-plans.products', [
        'onboarding' => [
            'name' => 'Onboarding',
            'price_net' => 25000,
            'onetimeonly' => true,
        ],
        'credit-pack' => [
            'name' => 'Credit Pack',
            'price_net' => 2000,
            'wallet' => 'Tokens',
            'amount' => 100,
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function productBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'products@example.test']);
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

/** One accepted CreatePaymentRequest, answered with a redirectable payment. */
function fakeProductPayment(string $paymentId): void
{
    Mollie::shouldReceive('send')->once()->andReturn(new class($paymentId)
    {
        public function __construct(public string $id) {}

        public function getCheckoutUrl(): string
        {
            return 'https://checkout.mollie.com/'.$this->id;
        }
    });
}

/** A paid one-time-order invoice, which is what `boughtBillingProducts()` reads. */
function purchasedProductInvoice(TestBillable $billable, string $code, int $net): void
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = InvoiceKind::OneTimeOrder;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->currency = 'EUR';
    $invoice->amount_net = $net;
    $invoice->amount_vat = 0;
    $invoice->amount_gross = $net;
    $invoice->refunded_net = 0;
    $invoice->line_items = [[
        'kind' => 'product',
        'code' => $code,
        'label' => $code,
        'quantity' => 1,
        'unit_price_net' => $net,
        'amount_net' => $net,
    ]];
    $invoice->save();
}

it('refuses to buy a one-time-only product a second time', function (): void {
    $billable = productBillable();
    purchasedProductInvoice($billable, 'onboarding', 25000);

    // Any Mollie call at all means the guard did not fire.
    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::products')
        ->call('purchase', 'onboarding')
        ->assertOk()
        ->assertSet('processing', false);

    expect(BillingInvoice::query()->count())->toBe(1);
});

it('still allows a repeatable product that was already bought', function (): void {
    $billable = productBillable();
    purchasedProductInvoice($billable, 'credit-pack', 2000);

    fakeProductPayment('tr_new_order');

    Livewire::test('mollie-billing::products')->call('purchase', 'credit-pack')->assertOk();
});

it('still allows a one-time-only product that has not been bought yet', function (): void {
    productBillable();

    fakeProductPayment('tr_first_order');

    Livewire::test('mollie-billing::products')->call('purchase', 'onboarding')->assertOk();
});

it('refuses a product code that is not in the catalog at all', function (): void {
    productBillable();

    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::products')
        ->call('purchase', 'does-not-exist')
        ->assertOk();
});
