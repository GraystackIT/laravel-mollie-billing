<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * `creditedPerOriginalLine()` deliberately skips credit lines without a
 * `parent_line_item_index` — they cannot be attributed to one original line. But the
 * invoice-wide "how much VAT is left to credit" cap used to be computed by summing
 * that map, so a credit note created with custom `line_items` was invisible to it and
 * a later automatic refund credited VAT that had already been given back.
 */
function unindexedCreditBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Unindexed Credit',
        'email' => uniqid('unindexed-').'@x.test',
        'billing_country' => 'AT',
    ]);

    return $b;
}

function unindexedOriginal(TestBillable $billable): BillingInvoice
{
    /** @var BillingInvoice $invoice */
    $invoice = BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_unindexed_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'line_items' => [[
            'kind' => 'plan',
            'label' => 'Plan',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
        ]],
    ]);

    return $invoice;
}

/** @param array<string, mixed> $line */
function unindexedCreditNote(TestBillable $billable, array $line): BillingInvoice
{
    /** @var BillingInvoice $note */
    $note = BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => (int) $line['amount_net'],
        'amount_vat' => (int) $line['vat_amount'],
        'amount_gross' => (int) $line['amount_gross'],
        'line_items' => [$line],
    ]);

    return $note;
}

it('counts VAT from a credit line that carries no parent line index', function (): void {
    $billable = unindexedCreditBillable();
    $original = unindexedOriginal($billable);

    // A custom credit line: points at the invoice, but no parent_line_item_index.
    unindexedCreditNote($billable, [
        'kind' => 'plan',
        'label' => 'Goodwill credit',
        'quantity' => 1,
        'parent_invoice_id' => (int) $original->getKey(),
        'amount_net' => -600,
        'vat_rate' => 20.0,
        'vat_amount' => -120,
        'amount_gross' => -720,
    ]);

    expect($original->creditedVatTotal())->toBe(120)
        // Still absent from the per-line map — that contract is unchanged.
        ->and($original->creditedPerOriginalLine())->toBe([]);
});

it('also counts an indexed credit line, so the two are not double-booked', function (): void {
    $billable = unindexedCreditBillable();
    $original = unindexedOriginal($billable);

    unindexedCreditNote($billable, [
        'kind' => 'plan',
        'label' => 'Partial refund',
        'quantity' => 1,
        'parent_invoice_id' => (int) $original->getKey(),
        'parent_line_item_index' => 0,
        'amount_net' => -400,
        'vat_rate' => 20.0,
        'vat_amount' => -80,
        'amount_gross' => -480,
    ]);

    expect($original->creditedVatTotal())->toBe(80)
        ->and($original->creditedPerOriginalLine()[0]['vat'])->toBe(80);
});

it('sums indexed and unindexed credit lines together', function (): void {
    // The case the cap got wrong: 80 of the 200 charged VAT was credited through an
    // indexed line and 100 through a custom one, so only 20 may still be credited.
    $billable = unindexedCreditBillable();
    $original = unindexedOriginal($billable);

    unindexedCreditNote($billable, [
        'kind' => 'plan',
        'label' => 'Partial refund',
        'quantity' => 1,
        'parent_invoice_id' => (int) $original->getKey(),
        'parent_line_item_index' => 0,
        'amount_net' => -400,
        'vat_rate' => 20.0,
        'vat_amount' => -80,
        'amount_gross' => -480,
    ]);

    unindexedCreditNote($billable, [
        'kind' => 'plan',
        'label' => 'Goodwill credit',
        'quantity' => 1,
        'parent_invoice_id' => (int) $original->getKey(),
        'amount_net' => -500,
        'vat_rate' => 20.0,
        'vat_amount' => -100,
        'amount_gross' => -600,
    ]);

    expect($original->creditedVatTotal())->toBe(180)
        ->and(abs((int) $original->amount_vat) - $original->creditedVatTotal())->toBe(20);
});

it('ignores credit lines belonging to a different invoice', function (): void {
    $billable = unindexedCreditBillable();
    $original = unindexedOriginal($billable);
    $other = unindexedOriginal($billable);

    unindexedCreditNote($billable, [
        'kind' => 'plan',
        'label' => 'Credit for the other invoice',
        'quantity' => 1,
        'parent_invoice_id' => (int) $other->getKey(),
        'amount_net' => -1000,
        'vat_rate' => 20.0,
        'vat_amount' => -200,
        'amount_gross' => -1200,
    ]);

    expect($original->creditedVatTotal())->toBe(0)
        ->and($other->creditedVatTotal())->toBe(200);
});
