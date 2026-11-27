<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The invoice archive's tiles after a credit note was taken back out of the books.
 *
 * A chargeback that is reversed (or a refund Mollie reports as failed) keeps its credit
 * note and adds a reversal document: kind Refund, status Paid, positive amounts. The tiles
 * summed every Paid row with a positive gross as "total paid" — so the reversal counted as
 * a second payment of the renewal (11.78 paid once read 23.56) — and "credits" still showed
 * the credit that had been reversed. Found by RefundDeepTest (browser), chargeback scenario.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => null, 'included_usages' => []]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function invoiceTilesBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Tiles Co', 'email' => uniqid('tiles-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill([
        'mollie_customer_id' => 'cst_tiles',
        'mollie_mandate_id' => 'mdt_tiles',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_tiles'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** 20.00 net, 19 % → 23.80, paid. */
function invoiceTilesRenewal(TestBillable $billable): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_tiles',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'DE',
        'currency' => 'EUR',
        'amount_net' => 2000,
        'amount_vat' => 380,
        'amount_gross' => 2380,
        'refunded_net' => 0,
        'line_items' => [[
            'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
            'unit_price_net' => 2000, 'amount_net' => 2000, 'total_net' => 2000,
            'vat_rate' => 19.0, 'vat_amount' => 380, 'amount_gross' => 2380,
        ]],
    ]);
}

it('counts a reversed credit note neither as a payment nor as a credit', function (): void {
    $billable = invoiceTilesBillable();
    $renewal = invoiceTilesRenewal($billable);
    $invoices = app(InvoiceService::class);

    // The bank pulls the renewal back …
    $invoices->createCreditNote($renewal, 2000, externalReference: ['key' => 'mollie_chargeback_id', 'value' => 'chb_tiles']);

    Livewire::test('mollie-billing::invoices')
        ->assertViewHas('stats', fn (array $stats): bool => $stats['paid'] === '€23.80'
            && $stats['credits'] === '€23.80'
            && $stats['spent'] === '€0.00');

    // … and the dispute is won: the credit note is reversed by a document of its own.
    $reversal = $invoices->reverseBookedCredit($billable, 'mollie_chargeback_id', 'chb_tiles', 'Reversal', $renewal);

    expect($reversal)->not->toBeNull()
        ->and($reversal->status)->toBe(InvoiceStatus::Paid)
        ->and((int) $reversal->amount_gross)->toBe(2380);

    // Paid once, nothing credited any more, and the balance is the one payment.
    Livewire::test('mollie-billing::invoices')
        ->assertViewHas('stats', fn (array $stats): bool => $stats['total'] === 3
            && $stats['paid'] === '€23.80'
            && $stats['credits'] === '€0.00'
            && $stats['spent'] === '€23.80');
});

it('keeps the credit of a partial refund that stands beside a reversed one', function (): void {
    $billable = invoiceTilesBillable();
    $renewal = invoiceTilesRenewal($billable);
    $invoices = app(InvoiceService::class);

    // 5.00 refunded and kept, 10.00 charged back and then reversed.
    $invoices->createCreditNote($renewal, 500, externalReference: ['key' => 'mollie_refund_id', 'value' => 're_tiles']);
    $invoices->createCreditNote($renewal, 1000, externalReference: ['key' => 'mollie_chargeback_id', 'value' => 'chb_tiles']);
    $invoices->reverseBookedCredit($billable, 'mollie_chargeback_id', 'chb_tiles', 'Reversal', $renewal);

    // 5.00 + 19 % = 5.95 credited; 23.80 − 5.95 = 17.85 spent.
    Livewire::test('mollie-billing::invoices')
        ->assertViewHas('stats', fn (array $stats): bool => $stats['paid'] === '€23.80'
            && $stats['credits'] === '€5.95'
            && $stats['spent'] === '€17.85');
});
