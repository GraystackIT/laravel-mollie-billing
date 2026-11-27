<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * The portal's country-fix modal tells the customer how many invoices "totalling" how much
 * will be refunded and reissued. It has to name the money `CountryMatchService::resolve()`
 * really moves: what is left of each linked invoice (docs/decisions.md, "A country
 * correction re-issues what remained paid"). It summed the invoices' original gross and
 * counted every linked invoice — a goodwill refund made before the mismatch was promised a
 * second time, and an invoice refunded in full was counted although nothing happens to it.
 *
 * Browser counterpart: CountryCorrectionDeepTest::test_a_partly_refunded_invoice_is_reissued_at_what_remained_paid_not_at_its_original_value.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['softdrinks'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', null);

    MollieBilling::authUsing(fn (): bool => true);
});

function countryFixModalBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'fixmodal@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'DE',
        'tax_country_user' => 'DE',
        'tax_country_payment' => 'NL',
        'tax_country_ip' => 'NL',
        'mollie_customer_id' => 'cst_fixmodal',
        'mollie_mandate_id' => 'mdt_fixmodal',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_fixmodal'],
    ])->save();

    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** A DE renewal of Pro (29.00) + Softdrinks (4.90): 33.90 net, 6.44 VAT, 40.34 gross. */
function countryFixModalRenewal(TestBillable $billable, int $mismatchId): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = 'tr_'.uniqid();
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'DE';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 3390;
    $invoice->amount_vat = 644;
    $invoice->amount_gross = 4034;
    $invoice->refunded_net = 0;
    $invoice->mismatch_id = $mismatchId;
    $invoice->line_items = [
        ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 2900, 'amount_net' => 2900, 'vat_rate' => 19.0, 'vat_amount' => 551, 'amount_gross' => 3451],
        ['kind' => 'addon', 'code' => 'softdrinks', 'label' => 'Softdrinks', 'quantity' => 1, 'unit_price_net' => 490, 'amount_net' => 490, 'vat_rate' => 19.0, 'vat_amount' => 93, 'amount_gross' => 583],
    ];
    $invoice->save();

    return $invoice;
}

/** A credit note of `$net` against one line of `$original`, booked before the mismatch. */
function countryFixModalCredit(BillingInvoice $original, int $lineIndex, int $net, int $vat): void
{
    $note = new BillingInvoice;
    $note->billable_type = $original->billable_type;
    $note->billable_id = $original->billable_id;
    $note->invoice_kind = InvoiceKind::Refund;
    $note->status = InvoiceStatus::Refunded;
    $note->country = 'DE';
    $note->currency = 'EUR';
    $note->amount_net = -$net;
    $note->amount_vat = -$vat;
    $note->amount_gross = -($net + $vat);
    $note->refunded_net = 0;
    $note->refund_reason_code = RefundReasonCode::Goodwill;
    $note->line_items = [[
        'kind' => 'refund', 'label' => 'Goodwill', 'quantity' => 1,
        'unit_price_net' => -$net, 'amount_net' => -$net, 'vat_rate' => 19.0, 'vat_amount' => -$vat, 'amount_gross' => -($net + $vat),
        'parent_invoice_id' => (int) $original->id, 'parent_line_item_index' => $lineIndex,
    ]];
    $note->save();

    $original->forceFill(['refunded_net' => (int) $original->refunded_net + $net])->save();
}

function countryFixModalMismatch(TestBillable $billable): BillingCountryMismatch
{
    return BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'DE',
        'tax_country_payment' => 'NL',
        'tax_country_ip' => 'NL',
        'status' => CountryMismatchStatus::Pending,
    ]);
}

it('names what is left of a partly refunded invoice, not its original gross', function (): void {
    $billable = countryFixModalBillable();
    $mismatch = countryFixModalMismatch($billable);

    $first = countryFixModalRenewal($billable, (int) $mismatch->id);
    countryFixModalCredit($first, 1, 490, 93);
    countryFixModalRenewal($billable, (int) $mismatch->id);

    // 29.00 + 19 % left of the first (34.51), all of the second (40.34).
    Livewire::test('mollie-billing::dashboard')
        ->assertSee('2 invoice(s) totalling €74.85 will be refunded')
        ->assertDontSee('€80.68');
});

it('leaves out an invoice that was refunded in full before the mismatch', function (): void {
    $billable = countryFixModalBillable();
    $mismatch = countryFixModalMismatch($billable);

    $refunded = countryFixModalRenewal($billable, (int) $mismatch->id);
    countryFixModalCredit($refunded, 0, 2900, 551);
    countryFixModalCredit($refunded, 1, 490, 93);
    countryFixModalRenewal($billable, (int) $mismatch->id);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee('1 invoice(s) totalling €40.34 will be refunded')
        ->assertDontSee('2 invoice(s)');
});

it('counts in the operator modal only the invoices the resolve will refund', function (): void {
    $billable = countryFixModalBillable();
    $mismatch = countryFixModalMismatch($billable);

    $refunded = countryFixModalRenewal($billable, (int) $mismatch->id);
    countryFixModalCredit($refunded, 0, 2900, 551);
    countryFixModalCredit($refunded, 1, 490, 93);
    countryFixModalRenewal($billable, (int) $mismatch->id);

    Livewire::test('mollie-billing::admin.mismatches.index')
        ->call('openResolve', $mismatch->id)
        ->assertSee(trans_choice('billing::admin.mismatches.modal.invoice_count', 1, ['count' => 1]))
        ->assertDontSee(trans_choice('billing::admin.mismatches.modal.invoice_count', 2, ['count' => 2]));
});
