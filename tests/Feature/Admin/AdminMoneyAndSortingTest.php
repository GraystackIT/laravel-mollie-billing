<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Money in the admin panel has to come out of BillingMoney everywhere, and per-row
 * currency has to win over the package default. The list screens also have to reject
 * sort columns that are not on their allow-list, or an unknown column reaches
 * orderBy() and wedges the page in a 500 loop.
 */
beforeEach(function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');
});

function moneyBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'money-'.uniqid().'@example.com',
        'name' => 'Money Tester',
        'billing_country' => 'DE',
    ])->save();

    return $b->refresh();
}

it('renders MRR and ARR through the shared formatter, honouring locale and currency', function (): void {
    Cache::flush();
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);
    app()->setLocale('de');

    $b = moneyBillable();
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    $invoice = new BillingInvoice;
    $invoice->billable_type = $b->getMorphClass();
    $invoice->billable_id = $b->getKey();
    $invoice->mollie_payment_id = 'tr_mrr_'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->amount_net = 123456;
    $invoice->amount_vat = 23457;
    $invoice->amount_gross = 146913;
    $invoice->line_items = [];
    $invoice->save();

    Livewire::test('mollie-billing::admin.dashboard')
        ->assertSee('£1.234,56')       // MRR: GBP symbol + German grouping
        ->assertSee('£14.814,72')      // ARR: MRR × 12, same formatter
        ->assertDontSee('€1.234,56')
        ->assertDontSee('1,234.56');
});

it('falls back to €0.00 rather than a hand-built string when there is no revenue', function (): void {
    app()->setLocale('en');

    Livewire::test('mollie-billing::admin.dashboard')->assertSee('€0.00');
});

it('renders refund-list and refund-detail amounts in the credit notes own currency', function (): void {
    app()->setLocale('en');

    $b = moneyBillable();

    $original = new BillingInvoice;
    $original->billable_type = $b->getMorphClass();
    $original->billable_id = $b->getKey();
    $original->mollie_payment_id = 'tr_orig_'.uniqid();
    $original->invoice_kind = InvoiceKind::Subscription;
    $original->status = InvoiceStatus::Paid;
    $original->country = 'DE';
    $original->currency = 'GBP';
    $original->amount_net = 100000;
    $original->amount_vat = 19000;
    $original->amount_gross = 119000;
    $original->line_items = [];
    $original->save();

    $note = new BillingInvoice;
    $note->billable_type = $b->getMorphClass();
    $note->billable_id = $b->getKey();
    $note->mollie_payment_id = 'tr_note_'.uniqid();
    $note->invoice_kind = InvoiceKind::Refund;
    $note->status = InvoiceStatus::Paid;
    $note->country = 'DE';
    $note->currency = 'GBP';
    $note->amount_net = -100000;
    $note->amount_vat = -19000;
    $note->amount_gross = -119000;
    $note->line_items = [[
        'kind' => 'plan',
        'label' => 'Plan refund',
        'quantity' => 1,
        'amount_net' => -100000,
        'amount_gross' => -119000,
        'parent_invoice_id' => $original->id,
    ]];
    $note->save();

    Livewire::test('mollie-billing::admin.refunds.index')
        ->assertSee('£1,190.00')
        ->call('openDetail', $note->id)
        ->assertSee('£1,000.00')   // net line in the net/VAT summary
        ->assertSee('£190.00')     // VAT line
        ->assertDontSee('€1,190.00');
});

it('renders coupon amounts in the coupon currency', function (): void {
    app()->setLocale('en');

    $coupon = Coupon::create([
        'code' => 'GBPFIXED',
        'name' => 'GBP fixed',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 100000,
        'minimum_order_amount_net' => 250000,
        'currency' => 'GBP',
        'active' => true,
    ]);

    Livewire::test('mollie-billing::admin.coupons.show', ['routeParameters' => ['coupon' => $coupon->id]])
        ->assertSee('£1,000.00')
        ->assertSee('£2,500.00')
        ->assertDontSee('€1,000.00');
});

it('feeds the fixed-discount hint from the formatter instead of a hardcoded symbol', function (): void {
    config()->set('mollie-billing.currency', 'GBP');
    config()->set('mollie-billing.currency_symbol', null);
    app()->setLocale('en');

    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'single_payment')
        ->set('discount_type', 'fixed')
        ->assertSee('500 = £5.00')
        ->assertDontSee('500 = €5.00');
});

it('escapes the validity hint so a published translation cannot inject markup', function (): void {
    app()->setLocale('en');

    app('translator')->addLines([
        'admin.coupons.create.validity_hint_default' => 'Injected <script>alert(1)</script> :max_total ok',
    ], 'en', 'billing');

    Livewire::test('mollie-billing::admin.coupons.create')
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        // Our own token is still rendered as real markup.
        ->assertSee('<span class="font-medium">', escape: false);
});

it('escapes the credits callout translation as well', function (): void {
    app()->setLocale('en');
    config()->set('mollie-billing-plans.plans', []);

    app('translator')->addLines([
        'admin.coupons.create.credits_no_usage_types' => 'Nope <img src=x onerror=alert(1)> :config_keys',
    ], 'en', 'billing');

    Livewire::test('mollie-billing::admin.coupons.create')
        ->set('type', 'credits')
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertSee('<code>included_usages</code>', escape: false);
});

it('ignores sort columns outside the allow-list on the past-due list', function (): void {
    $b = moneyBillable();
    $b->forceFill(['subscription_status' => SubscriptionStatus::PastDue])->save();

    Livewire::test('mollie-billing::admin.past-due.index')
        ->call('sort', 'no_such_column')
        ->assertSet('sortBy', 'updated_at')
        ->assertOk();
})->group('admin-sorting');

it('ignores sort columns outside the allow-list on the coupons list', function (): void {
    Coupon::create([
        'code' => 'SORTME',
        'name' => 'Sort me',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'active' => true,
    ]);

    Livewire::test('mollie-billing::admin.coupons.index')
        ->call('sort', 'no_such_column')
        ->assertSet('sortBy', 'created_at')
        ->assertOk();
})->group('admin-sorting');

it('still renders when a stale client pushes an unknown sort column', function (): void {
    $b = moneyBillable();
    $b->forceFill(['subscription_status' => SubscriptionStatus::PastDue])->save();

    Livewire::test('mollie-billing::admin.past-due.index')
        ->set('sortBy', 'no_such_column')
        ->assertOk();

    Livewire::test('mollie-billing::admin.coupons.index')
        ->set('sortBy', 'no_such_column')
        ->assertOk();
})->group('admin-sorting');
