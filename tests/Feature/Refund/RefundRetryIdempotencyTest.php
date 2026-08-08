<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * A refund that Mollie created but whose response was lost must be replayed, never
 * re-issued.
 *
 * The idempotency key used to end in fresh randomness on every attempt. That solved one
 * problem — Mollie rejects two legitimately identical refunds on one payment as
 * duplicates — and created a worse one: the retry of a refund whose HTTP response was
 * lost arrived as a *new* operation and paid the customer a second time.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function retryRefundBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Retry Ltd',
        'email' => uniqid('retry-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function retryRefundInvoice(TestBillable $billable, int $net = 5000): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_retry_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => (int) round($net * 0.2),
        'amount_gross' => $net + (int) round($net * 0.2),
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => $net,
            'amount_net' => $net,
            'vat_rate' => 20.0,
            'vat_amount' => (int) round($net * 0.2),
            'amount_gross' => $net + (int) round($net * 0.2),
        ]],
    ])->refresh();
}

function retryRefundLine(BillingInvoice $invoice, int $net): ProrataLine
{
    return new ProrataLine(
        originalInvoice: $invoice,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'pro',
        label: 'Pro',
        quantity: 1,
        amountNet: -$net,
        vatRate: 20.0,
        amountVat: -(int) round($net * 0.2),
        amountGross: -($net + (int) round($net * 0.2)),
        periodStart: BillingTime::nowUtc()->subDays(5),
        periodEnd: BillingTime::nowUtc()->addDays(25),
        daysActive: 5,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );
}

it('carries one idempotency token through a lost response and its retry', function (): void {
    $billable = retryRefundBillable();
    $invoice = retryRefundInvoice($billable);
    $line = retryRefundLine($invoice, 4000);

    $keys = [];
    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (string $key) use (&$keys) {
        $keys[] = $key;

        return Mollie::getFacadeRoot();
    });

    // Attempt one: Mollie creates the refund, the response is lost.
    Mollie::shouldReceive('send')->once()->andThrow(new RuntimeException('read timeout'));

    app(InvoiceService::class)->createRefund($billable, [$line], 'First attempt');

    // What the retry job replays is the *serialised* line out of pending_refund_retries.
    $stored = $billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0]['line'] ?? null;
    expect($stored)->not->toBeNull()
        ->and($stored['refund_token'] ?? null)->toBeString();

    $replayed = ProrataLine::fromArray($stored);

    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 're_1']);

    app(InvoiceService::class)->createRefund($billable, [$replayed], 'Refund retry');

    // Same key both times: Mollie replays its stored response instead of creating a
    // second refund. A per-attempt random suffix produced two distinct keys here, which
    // is exactly a second payout.
    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1]);
});

it('sees a credit note whose refunded_net write was lost', function (): void {
    $billable = retryRefundBillable();
    $invoice = retryRefundInvoice($billable, 5000);

    // The state a lost `refunded_net` write leaves behind: the credit note exists, the
    // cache column does not know about it. Reading the raw column reported the whole
    // invoice as refundable again.
    BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => null,
        'invoice_kind' => InvoiceKind::Refund,
        'status' => InvoiceStatus::Refunded,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => -4000,
        'amount_vat' => -800,
        'amount_gross' => -4800,
        'serial_number' => 'CN-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'amount_net' => -4000,
            'vat_amount' => -800,
            'amount_gross' => -4800,
            'parent_invoice_id' => $invoice->getKey(),
            'parent_line_item_index' => 0,
        ]],
    ]);

    expect($invoice->fresh()->refunded_net)->toBe(0)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);

    $sent = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$sent) {
        $sent[] = (new ReflectionObject($request))->getProperty('amount')->getValue($request)->value;

        return (object) ['id' => 're_2'];
    });

    // Only 1000 net is left. Clamped against the raw column this would have sent the
    // full 4000 again.
    app(InvoiceService::class)->createRefund($billable, [retryRefundLine($invoice, 4000)], 'Second refund');

    expect($sent)->toBe(['12.00']);
});
