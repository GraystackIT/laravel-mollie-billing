<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function idemInvoice(): BillingInvoice
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Idem Co', 'email' => uniqid('idem-').'@x.test', 'billing_country' => 'AT']);

    return BillingInvoice::create([
        'billable_type' => $b->getMorphClass(),
        'billable_id' => $b->getKey(),
        'mollie_payment_id' => 'tr_idem',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1000,
        'amount_vat' => 200,
        'amount_gross' => 1200,
        'serial_number' => 'IN-idem-'.uniqid(),
        'line_items' => [[
            'kind' => 'plan',
            'code' => 'pro',
            'label' => 'Pro',
            'quantity' => 1,
            'unit_price_net' => 1000,
            'amount_net' => 1000,
            'vat_rate' => 20.0,
            'vat_amount' => 200,
            'amount_gross' => 1200,
        ]],
    ]);
}

it('replays the same idempotency key when a failed refund is retried', function (): void {
    // A refund call that times out on the wire may well have arrived. Nothing booked, so
    // `refunded_net` is untouched and the same amount validates as refundable again — an
    // operator clicking the error away, or a queued retry, paid the customer a second
    // time. The key is what makes Mollie answer the retry with the refund it already made.
    $invoice = idemInvoice();
    $keys = [];

    Mollie::shouldReceive('api')->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function ($key) use (&$keys) {
        $keys[] = $key;

        return Mollie::getFacadeRoot();
    });
    Mollie::shouldReceive('send')->andThrow(new \RuntimeException('gateway timeout'));

    foreach ([1, 2] as $attempt) {
        try {
            app(RefundInvoiceService::class)->refund($invoice->fresh(), [
                'amount_net' => 400,
                'reason_code' => RefundReasonCode::Goodwill,
            ]);
        } catch (\Throwable) {
            // Expected — the point is what was sent, not that it worked.
        }
    }

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and($keys[0])->toContain('invoice-refund:');
});

it('uses a different key for a deliberate second refund of the same amount', function (): void {
    // Two partial refunds of the same size for the same reason are a legitimate sequence,
    // and must not be collapsed into one by the key. What separates them from a retry is
    // that the first one booked: the key carries how much was already credited back.
    $invoice = idemInvoice();
    $keys = [];

    Mollie::shouldReceive('api')->andReturnSelf();
    Mollie::shouldReceive('resetIdempotencyKey')->andReturnSelf();
    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function ($key) use (&$keys) {
        $keys[] = $key;

        return Mollie::getFacadeRoot();
    });
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'rf_ok']);

    $service = app(RefundInvoiceService::class);
    $service->refund($invoice->fresh(), ['amount_net' => 400, 'reason_code' => RefundReasonCode::Goodwill]);
    $service->refund($invoice->fresh(), ['amount_net' => 400, 'reason_code' => RefundReasonCode::Goodwill]);

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});
