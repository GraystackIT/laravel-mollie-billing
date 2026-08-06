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
 * The portal and the checkout used to derive the currency symbol themselves —
 * `$currency === 'EUR' ? '€' : $currency` in six places, a hardcoded `€` in three
 * more — and format with `number_format()`, which ignores the locale. Everything now
 * goes through BillingMoney, so a GBP/CHF setup and a German locale render correctly
 * and identically to the admin panel.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => [
                'monthly' => ['base_price_net' => 3000, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => 30000, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => [
            'name' => 'Extra',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 1000], 'yearly' => ['price_net' => 10000]],
        ],
    ]);
    config()->set('mollie-billing-plans.products', [
        'credit-pack' => ['name' => 'Credit Pack', 'price_net' => 2000, 'wallet' => 'Tokens', 'amount' => 100],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function makeMoneyBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'money@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_123',
        'mollie_mandate_id' => 'mdt_test_123',
        'subscription_meta' => ['seat_count' => 2, 'used_seats' => 2, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function makeMoneyInvoice(TestBillable $billable, int $net, ?string $currency = null): BillingInvoice
{
    $vat = (int) round($net * 20 / 100);

    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = 'subscription';
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
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

// ── (5) The six portal sites that ignored `currency_symbol` ────────────────────

// The billable is Austrian without reverse charge, so every portal price below is
// the VAT-inclusive display price (net + 20%).
it('renders the GBP symbol instead of the ISO code in the portal', function (): void {
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);

    makeMoneyBillable();

    Livewire::test('mollie-billing::seats')
        ->assertSee('£6.00')
        ->assertDontSee('GBP6.00');

    Livewire::test('mollie-billing::addons')
        ->assertSee('£12.00')
        ->assertDontSee('GBP12.00');

    Livewire::test('mollie-billing::products')
        ->assertSee('£24.00')
        ->assertDontSee('GBP24.00');

    Livewire::test('mollie-billing::plan-change')
        ->assertSee('£36.00')
        ->assertDontSee('GBP36.00');
});

it('separates an unmapped currency code from the amount', function (): void {
    config()->set('mollie-billing.currency', 'CHF');
    config()->set('mollie-billing.currency_symbol', null);

    makeMoneyBillable();

    // "CHF 36.00", never "CHF36.00" — the ISO fallback carries its own space.
    Livewire::test('mollie-billing::plan-change')
        ->assertSee('CHF 36.00')
        ->assertDontSee('CHF36.00');
});

it('honours an explicitly configured currency symbol in the portal', function (): void {
    config()->set('mollie-billing.currency', 'CHF');
    config()->set('mollie-billing.currency_symbol', 'Fr. ');

    makeMoneyBillable();

    Livewire::test('mollie-billing::seats')->assertSee('Fr. 6.00');
});

// ── (6)/(7)/(10) The checkout steps ───────────────────────────────────────────

it('renders the configured symbol on the checkout grand total', function (): void {
    config()->set('mollie-billing.currency', 'CHF');
    config()->set('mollie-billing.currency_symbol', 'CHF ');
    MollieBilling::resolveBillableUsing(fn () => null);

    // Confirm step (no custom steps: 1 billing, 2 plan, 3 addons, 4 confirm).
    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('billing_country', 'AT')
        ->set('step', 4)
        // net 30.00, 20% AT VAT 6.00, gross 36.00 — the total used to be hardcoded to €.
        ->assertSee('CHF 30.00')
        ->assertSee('CHF 6.00')
        ->assertSee('CHF 36.00')
        ->assertDontSee('€');
});

it('renders the configured symbol on the checkout addon and seat prices', function (): void {
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);
    MollieBilling::resolveBillableUsing(fn () => null);

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('billing_country', 'AT')
        ->set('step', 3)
        // Addon 10.00 net + 20% = 12.00; seat 5.00 net + 20% = 6.00.
        ->assertSee('£12.00')
        ->assertSee('£6.00')
        ->assertDontSee('€');
});

it('renders the configured symbol on the checkout plan cards', function (): void {
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);
    MollieBilling::resolveBillableUsing(fn () => null);

    Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'AT')
        ->set('step', 2)
        ->assertSee('£36.00')
        ->assertDontSee('€');
});

// ── (8)/(9) Per-invoice currency ──────────────────────────────────────────────

it('renders each invoice row in its own currency', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);

    $billable = makeMoneyBillable();
    makeMoneyInvoice($billable, 1000, 'EUR');
    makeMoneyInvoice($billable, 2000, 'GBP');

    Livewire::test('mollie-billing::invoices')
        ->assertSee('€10.00')
        ->assertSee('£20.00')
        ->assertDontSee('€20.00');
});

it('renders the dashboard invoice strip in each invoice currency', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);

    $billable = makeMoneyBillable();
    makeMoneyInvoice($billable, 1000, 'EUR');
    makeMoneyInvoice($billable, 2000, 'GBP');

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('€10.00')
        ->assertSee('£20.00')
        ->assertDontSee('€20.00');
});

// ── (11) Locale-aware decimal separators ──────────────────────────────────────

it('uses the German decimal separator in the portal', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);
    app()->setLocale('de');

    $billable = makeMoneyBillable();
    makeMoneyInvoice($billable, 123456, 'EUR');

    Livewire::test('mollie-billing::invoices')
        ->assertSee('€1.234,56')
        ->assertDontSee('€1,234.56');
})->skip(! class_exists(NumberFormatter::class), 'ext-intl not installed');

it('uses the German decimal separator in the checkout', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.base_price_net', 123456);
    app()->setLocale('de');
    MollieBilling::resolveBillableUsing(fn () => null);

    Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('billing_country', 'AT')
        ->set('step', 4)
        ->assertSee('€1.234,56')
        ->assertDontSee('€1,234.56');
})->skip(! class_exists(NumberFormatter::class), 'ext-intl not installed');

// ── (13) The savings badge honours its config key ─────────────────────────────

it('hides the yearly savings badge when the config key is off', function (): void {
    config()->set('mollie-billing.show_yearly_savings', false);

    makeMoneyBillable();

    Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'yearly')
        ->assertDontSee(__('billing::portal.save_up_to', ['percent' => 17]));
});

it('shows the yearly savings badge by default', function (): void {
    config()->set('mollie-billing.show_yearly_savings', true);

    makeMoneyBillable();

    Livewire::test('mollie-billing::plan-change')
        ->set('selectedInterval', 'yearly')
        ->assertSee(__('billing::portal.save_up_to', ['percent' => 17]));
});
