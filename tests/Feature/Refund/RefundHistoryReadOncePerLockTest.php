<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * `createRefund()` asks two questions of every line it posts — "is this refund already on the
 * books?" and, for a line whose earlier answer was lost, "does Mollie hold it already?".
 *
 * Each question used to be answered from scratch per line: the billable's whole credit-note
 * history loaded and decoded again (up to three times per retried line), and the payment's
 * whole refund list paged through Mollie again for every line of the same payment. Within
 * one refund lock neither can change under us, so they are read once per lock.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function historyOnceBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'History Ltd',
        'email' => uniqid('history-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function historyOnceInvoice(TestBillable $billable): BillingInvoice
{
    $lines = [];

    foreach (['plan', 'seats', 'addon'] as $kind) {
        $lines[] = [
            'kind' => $kind,
            'code' => $kind === 'seats' ? null : 'pro',
            'label' => ucfirst($kind),
            'quantity' => 1,
            'unit_price_net' => 2000,
            'amount_net' => 2000,
            'vat_rate' => 20.0,
            'vat_amount' => 400,
            'amount_gross' => 2400,
        ];
    }

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_history_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 6000,
        'amount_vat' => 1200,
        'amount_gross' => 7200,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => $lines,
    ])->refresh();
}

/**
 * A line whose earlier refund call threw: it carries its token and asks Mollie before posting.
 */
function historyOnceRetriedLine(BillingInvoice $invoice, int $index): ProrataLine
{
    $line = new ProrataLine(
        originalInvoice: $invoice,
        originalLineItemIndex: $index,
        kind: $invoice->line_items[$index]['kind'],
        code: $invoice->line_items[$index]['code'],
        label: $invoice->line_items[$index]['label'],
        quantity: 1,
        amountNet: -1000,
        vatRate: 20.0,
        amountVat: -200,
        amountGross: -1200,
        periodStart: BillingTime::nowUtc()->subDays(5),
        periodEnd: BillingTime::nowUtc()->addDays(25),
        daysActive: 5,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );

    $line->refundToken = 'tok'.$index;
    $line->refundOutcomeUnknown = true;

    return $line;
}

function countCreditNoteHistoryReads(callable $run): int
{
    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'select "line_items" from "billing_invoices"')) {
            $reads++;
        }
    });

    $run();

    return $reads;
}

it('reads the credit-note history and the Mollie refund list once for three lines of one payment', function (): void {
    $billable = historyOnceBillable();
    $invoice = historyOnceInvoice($billable);

    // A refund history to read: an earlier, unrelated credit note.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => -500,
        'amount_vat' => -100,
        'amount_gross' => -600,
        'serial_number' => 'CN-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan', 'label' => 'Earlier', 'quantity' => 1,
            'amount_net' => -500, 'vat_rate' => 20.0, 'vat_amount' => -100, 'amount_gross' => -600,
            'parent_invoice_id' => $invoice->getKey(), 'parent_line_item_index' => 0,
            'mollie_refund_id' => 're_earlier', 'refund_token' => 'tok_earlier',
        ]],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();

    // Asked once for the payment, not once per line.
    Mollie::shouldReceive('send')->once()
        ->with(Mockery::type(GetPaginatedPaymentRefundsRequest::class))
        ->andReturn(new ArrayIterator([]));

    $created = 0;
    Mollie::shouldReceive('send')->times(3)
        ->with(Mockery::type(CreatePaymentRefundRequest::class))
        ->andReturnUsing(function () use (&$created): object {
            $created++;

            return (object) ['id' => 're_new_'.$created];
        });

    $lines = [0, 1, 2];
    $reads = countCreditNoteHistoryReads(function () use ($billable, $invoice, $lines): void {
        $note = app(InvoiceService::class)->createRefund(
            $billable,
            array_map(fn (int $i): ProrataLine => historyOnceRetriedLine($invoice, $i), $lines),
            'Plan change',
        );

        expect($note)->not->toBeNull()
            ->and(collect($note->line_items)->pluck('mollie_refund_id')->all())
            ->toBe(['re_new_1', 're_new_2', 're_new_3']);
    });

    // One read for the booking lookups of all three lines, one for the original invoice's
    // own memoised `refundChildren()` (effectiveRefundedNet). Per line and question it was 7.
    expect($reads)->toBe(2);
});

it('still settles a line whose refund the books already hold, from the single read', function (): void {
    $billable = historyOnceBillable();
    $invoice = historyOnceInvoice($billable);

    // Line 1's refund was booked by the webhook from its Mollie metadata while its answer was lost.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => -1000,
        'amount_vat' => -200,
        'amount_gross' => -1200,
        'serial_number' => 'CN-'.uniqid(),
        'line_items' => [[
            'kind' => 'seats', 'label' => 'Synced', 'quantity' => 1,
            'amount_net' => -1000, 'vat_rate' => 20.0, 'vat_amount' => -200, 'amount_gross' => -1200,
            'parent_invoice_id' => $invoice->getKey(), 'parent_line_item_index' => 1,
            'mollie_refund_id' => 're_synced', 'refund_token' => 'tok1',
        ]],
    ]);

    Mollie::shouldReceive('setIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('send')->once()
        ->with(Mockery::type(GetPaginatedPaymentRefundsRequest::class))
        ->andReturn(new ArrayIterator([]));
    Mollie::shouldReceive('send')->twice()
        ->with(Mockery::type(CreatePaymentRefundRequest::class))
        ->andReturn((object) ['id' => 're_a'], (object) ['id' => 're_b']);

    $note = app(InvoiceService::class)->createRefund(
        $billable,
        [historyOnceRetriedLine($invoice, 0), historyOnceRetriedLine($invoice, 1), historyOnceRetriedLine($invoice, 2)],
        'Plan change',
    );

    expect(collect($note->line_items)->pluck('refund_token')->all())->toBe(['tok0', 'tok2']);
});
