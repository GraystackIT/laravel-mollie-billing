<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Events\CreditNoteIssued;
use GraystackIT\MollieBilling\Events\InvoiceRefunded;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Notifications\RefundProcessedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * An app listener that throws after a refund has been paid out and booked must not turn
 * into a failed refund.
 *
 * `CreditNoteIssued` and `InvoiceRefunded` are dispatched synchronously after the money has
 * left Mollie and the credit note is committed. A listener throwing there (a CRM push, an
 * audit sink) escaped to the caller: RetryRefundLineJob failed and the queue retried the
 * same line — clamped to what was left, so under a new idempotency key, paying out again —
 * and the admin screen and the superseded-renewal mail reported a refund as failed that had
 * gone out, while the customer's RefundProcessedNotification was never sent.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function listenerFailureBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Listener Ltd',
        'email' => uniqid('listener-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function listenerFailureInvoice(TestBillable $billable, int $net): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_listener_'),
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

it('does not fail the retry job, nor pay again, when a CreditNoteIssued listener throws', function (): void {
    $billable = listenerFailureBillable();
    $invoice = listenerFailureInvoice($billable, 10000);

    // 60.00 net of a 100.00 invoice, as RetryRefundLineJob carries it out of the marker.
    $line = new ProrataLine(
        originalInvoice: $invoice,
        originalLineItemIndex: 0,
        kind: 'plan',
        code: 'pro',
        label: 'Pro',
        quantity: 1,
        amountNet: -6000,
        vatRate: 20.0,
        amountVat: -1200,
        amountGross: -7200,
        periodStart: BillingTime::nowUtc()->subDays(5),
        periodEnd: BillingTime::nowUtc()->addDays(25),
        daysActive: 5,
        daysRemaining: 25,
        isCouponCovered: false,
        direction: 'refund',
    );
    $line->refundToken = 'tok-listener';
    $lineData = $line->toArray();

    Event::listen(CreditNoteIssued::class, function (): void {
        throw new RuntimeException('crm down');
    });

    // Mollie: a new key is a new refund.
    $key = null;
    $byKey = [];
    $posts = 0;
    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (?string $k) use (&$key) {
        $key = $k;

        return Mollie::getFacadeRoot();
    });
    Mollie::shouldReceive('send')->andReturnUsing(function (object $request) use (&$key, &$byKey, &$posts) {
        expect($request)->toBeInstanceOf(CreatePaymentRefundRequest::class);
        $posts++;

        return $byKey[(string) $key] ??= (object) ['id' => 're_'.(count($byKey) + 1)];
    });

    $failures = 0;

    // As the queue runs it: an attempt that throws is attempted again with the same payload.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            (new RetryRefundLineJob($billable::class, $billable->getKey(), $lineData, BillingTime::nowUtc()->toIso8601String()))
                ->handle(app(InvoiceService::class));

            break;
        } catch (Throwable) {
            $failures++;
        }
    }

    expect($failures)->toBe(0)
        ->and($posts)->toBe(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(6000);
});

it('returns the credit note and tells the customer when an InvoiceRefunded listener throws', function (): void {
    $this->app->bind(RefundInvoiceService::class, function ($app): RefundInvoiceService {
        return new class ($app->make(InvoiceService::class), $app->make(WalletUsageService::class)) extends RefundInvoiceService {
            protected function callMollieRefund(string $paymentId, int $grossCents, string $description, string $currency): ?string
            {
                return 're_admin_'.$grossCents;
            }
        };
    });

    Notification::fake();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    Event::listen(InvoiceRefunded::class, function (): void {
        throw new RuntimeException('crm down');
    });
    Event::listen(CreditNoteIssued::class, function (): void {
        throw new RuntimeException('audit sink down');
    });

    $billable = listenerFailureBillable();
    $invoice = listenerFailureInvoice($billable, 10000);

    $creditNote = app(RefundInvoiceService::class)->refundPartially($invoice, 3000, RefundReasonCode::Goodwill);

    expect($creditNote->invoice_kind)->toBe(InvoiceKind::Refund)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(3000);

    Notification::assertSentTo($billable, RefundProcessedNotification::class);
});
