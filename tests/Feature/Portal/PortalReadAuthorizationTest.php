<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * The mutating portal actions were hardened to call `MollieBilling::authorizes()`
 * themselves; the read paths were not. Nothing else covers them: the package's portal
 * routes (`routes/web.php`) never apply the `billing.portal` middleware, and even where a
 * host app wraps them in its own gate, a Livewire update request posts to
 * `/livewire/update` and only re-runs the middleware listed in
 * `Livewire::addPersistentMiddleware()` — which re-applies a middleware only if the
 * original route carried it.
 *
 * So `mount()` and `with()` are the only place that can refuse, and a member the host app
 * denies billing management to must not be able to read the tenant's addresses, invoices,
 * VAT number, wallet balances or pro-rata previews.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['print-gateway'],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 3000,
                    'seat_price_net' => 500,
                    'included_usages' => ['tokens' => 1000],
                ],
                'yearly' => [
                    'base_price_net' => 30000,
                    'seat_price_net' => 5000,
                    'included_usages' => ['tokens' => 12000],
                ],
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 990], 'yearly' => ['price_net' => 9900]],
    ]);
    config()->set('mollie-billing-plans.products.credit-pack', [
        'name' => 'Credit Pack',
        'price_net' => 2000,
        'wallet' => 'Tokens',
        'amount' => 100,
    ]);
    config()->set('mollie-billing-plans.usages.tokens', ['name' => 'Tokens', 'rollover' => false]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Confidential Holdings', 'email' => 'secret@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_street' => 'Secret Lane 42',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'vat_number' => 'ATU99887766',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
        'subscription_meta' => ['seat_count' => 2, 'used_seats' => 2, 'mollie_subscription_id' => 'sub_test'],
    ])->save();

    $billable = $billable->refresh();
    $this->billable = $billable;

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_read_gate';
    $invoice->invoice_kind = 'subscription';
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 3000;
    $invoice->amount_vat = 600;
    $invoice->amount_gross = 3600;
    $invoice->refunded_net = 0;
    $invoice->serial_number = 'INV-2026-000777';
    $invoice->line_items = [];
    $invoice->save();

    MollieBilling::resolveBillableUsing(fn () => $billable);
});

/** Strings that only appear once the component has actually read the billable. */
dataset('portal reads', [
    'dashboard'      => ['mollie-billing::dashboard', 'Confidential Holdings'],
    'invoices'       => ['mollie-billing::invoices', '€36.00'],
    'usage-history'  => ['mollie-billing::usage-history', 'Tokens'],
    'seats'          => ['mollie-billing::seats', '€6.00'],
    'addons'         => ['mollie-billing::addons', 'Print Gateway'],
    'products'       => ['mollie-billing::products', 'Credit Pack'],
]);

it('renders nothing about the billable when authorization is denied', function (string $component, string $needle): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test($component)->assertDontSee($needle);
})->with('portal reads');

it('renders the billable once the host app grants authorization', function (string $component, string $needle): void {
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test($component)->assertSee($needle);
})->with('portal reads');

// billing-data renders its values into form inputs rather than into text, so the
// component state is where the read shows up.
it('does not fill the billing-data form when authorization is denied', function (): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::billing-data')
        ->assertSet('billing_street', '')
        ->assertSet('vat_number', null)
        ->assertDontSee('ATU99887766');
});

it('fills the billing-data form once the host app grants authorization', function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::billing-data')
        ->assertSet('billing_street', 'Secret Lane 42')
        ->assertSet('billing_city', 'Vienna')
        ->assertSet('vat_number', 'ATU99887766');
});

it('does not seed the seat count when authorization is denied', function (): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::seats')->assertSet('seatCount', 0);
});

it('does not price a plan change when authorization is denied', function (): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::plan-change')
        ->call('previewFor', 'starter')
        ->assertSet('preview', []);
});

it('prices a plan change once the host app grants authorization', function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    $component = Livewire::test('mollie-billing::plan-change')->call('previewFor', 'starter');

    expect($component->get('preview'))->not->toBe([]);
});

it('does not prefill the plan-change billing modal when authorization is denied', function (): void {
    MollieBilling::authUsing(fn (): bool => false);

    Livewire::test('mollie-billing::plan-change')
        ->call('openEditBillingModal')
        ->assertSet('showEditBillingModal', false)
        ->assertSet('billing_street', '');
});
