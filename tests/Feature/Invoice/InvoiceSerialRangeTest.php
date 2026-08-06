<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * The generator finds the year's highest serial with a bounded `>= … <= …` range
 * rather than a LIKE pattern, so that `FOR UPDATE` locks one index record instead
 * of every row it scanned. That only holds if the two bounds cover exactly what the
 * pattern covered — this file pins that equivalence, including for custom formats
 * and for the prefixes that must stay in disjoint ranges.
 */
function serialBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Serial '.uniqid(),
        'email' => uniqid('serial-').'@x.test',
        'billing_country' => 'AT',
    ]);

    return $b;
}

function persistSerial(string $serial, TestBillable $billable): void
{
    BillingInvoice::create([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'serial_number' => $serial,
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'currency' => 'EUR',
        'country' => 'AT',
        'line_items' => [],
    ]);
}

it('continues the year it is in and ignores other years', function (): void {
    $billable = serialBillable();
    $year = date('y');
    $other = str_pad((string) (((int) $year + 1) % 100), 2, '0', STR_PAD_LEFT);

    persistSerial("IN-{$year}000007", $billable);
    // A neighbouring year must not be read as this year's high-water mark, in
    // either direction: 99 sorts above and 00 below.
    persistSerial("IN-{$other}000999", $billable);

    expect((new InvoiceNumberGenerator)->generate('invoice'))->toBe("IN-{$year}000008");
});

it('keeps the invoice and credit-note sequences in disjoint ranges', function (): void {
    // The two prefixes each get their own range, which is what stops an admin refund
    // and a renewal webhook from contending on the same rows.
    $billable = serialBillable();
    $year = date('y');

    persistSerial("IN-{$year}000004", $billable);
    persistSerial("CR-{$year}000011", $billable);

    expect((new InvoiceNumberGenerator)->generate('invoice'))->toBe("IN-{$year}000005")
        ->and((new InvoiceNumberGenerator)->generate('credit_note'))->toBe("CR-{$year}000012")
        ->and((new InvoiceNumberGenerator)->generate('refund'))->toBe("CR-{$year}000012");
});

it('starts at one when the year is empty', function (): void {
    serialBillable();
    $year = date('y');

    expect((new InvoiceNumberGenerator)->generate('invoice'))->toBe("IN-{$year}000001");
});

it('honours a custom format, including one with no separator', function (): void {
    $billable = serialBillable();
    config()->set('mollie-billing.invoices.serial_number.format', 'PPPYYCCC');
    config()->set('mollie-billing.invoices.serial_number.prefix', ['invoice' => 'INV']);
    $year = date('y');

    persistSerial("INV{$year}041", $billable);

    expect((new InvoiceNumberGenerator)->generate('invoice'))->toBe("INV{$year}042");
});

it('refuses to restart the counter when it outgrows its slot', function (): void {
    // Past the slot width str_pad stops padding and the serial grows a digit, which
    // sorts *below* the upper bound — so the overflowed row becomes invisible, the
    // counter silently restarts at 1, and the unique index rejects the invoice after
    // the payment has already been taken. Fail loudly while nothing is lost.
    $billable = serialBillable();
    config()->set('mollie-billing.invoices.serial_number.format', 'PP-YYC');
    $year = date('y');

    persistSerial("IN-{$year}9", $billable);

    expect(fn () => (new InvoiceNumberGenerator)->generate('invoice'))
        ->toThrow(RuntimeException::class, 'overflowed');
});
