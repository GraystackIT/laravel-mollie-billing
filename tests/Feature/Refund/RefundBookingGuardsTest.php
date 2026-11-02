<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Exceptions\RefundInProgressException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\RefundInvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Cache;
use Mollie\Laravel\Facades\Mollie;

function refundGuardBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Refund Guard Co',
        'email' => uniqid('refund-guard-').'@x.test',
        'billing_country' => 'AT',
    ]);

    return $billable->refresh();
}

function overageInvoiceFor(TestBillable $billable): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_overage_'.uniqid(),
        'invoice_kind' => InvoiceKind::Overage,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 20000,
        'amount_vat' => 4000,
        'amount_gross' => 24000,
        'serial_number' => 'IN-ovg-'.uniqid(),
        'line_items' => [
            ['kind' => 'overage', 'type' => 'tokens', 'label' => 'Tokens', 'quantity' => 1000,
                'unit_price_net' => 10, 'amount_net' => 10000, 'vat_rate' => 20.0, 'vat_amount' => 2000, 'amount_gross' => 12000],
            ['kind' => 'overage', 'type' => 'sms', 'label' => 'SMS', 'quantity' => 1000,
                'unit_price_net' => 10, 'amount_net' => 10000, 'vat_rate' => 20.0, 'vat_amount' => 2000, 'amount_gross' => 12000],
        ],
    ]);
}

// The bulk helper summed `unit price × units` into the refund amount and forwarded the
// same map to the wallet, which ignores non-positive quantities. A negative entry for one
// type therefore paid for a positive one on another: 1000 tokens credited back for the
// money of 100.
it('refuses a non-positive unit count in a bulk overage refund', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $invoice = overageInvoiceFor(refundGuardBillable());

    expect(fn () => app(RefundInvoiceService::class)->refundOverageUnitsBulk(
        $invoice,
        ['tokens' => 1000, 'sms' => -900],
        RefundReasonCode::Goodwill,
    ))->toThrow(\InvalidArgumentException::class);
});

it('refuses to credit back more units than the invoice billed', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();

    $invoice = overageInvoiceFor(refundGuardBillable());

    // 1000 tokens were billed. 2000 fits inside the invoice's money (the SMS line pays for
    // the second half), so the amount cap does not catch it — but half of those tokens
    // were never sold and would be handed out for free.
    expect(fn () => app(RefundInvoiceService::class)->refundOverageUnits(
        $invoice,
        'tokens',
        2000,
        RefundReasonCode::Goodwill,
    ))->toThrow(\InvalidArgumentException::class);
});

// RefundInvoiceService creates the refund at Mollie in phase 2 and books it in phase 3.
// Mollie calls the payment webhook as soon as the refund exists, and RefundHandler only
// dedups on a refund id that phase 3 has not stamped yet — so it booked a "synced from
// dashboard" credit note, and phase 3 then booked a second one for the same money.
it('defers a webhook refund sync while a programmatic refund of the invoice is in flight', function (): void {
    $billable = refundGuardBillable();
    $invoice = overageInvoiceFor($billable);

    $lock = Cache::lock('billing:invoice-refund:'.$invoice->id, 120);
    expect($lock->get())->toBeTrue();

    $payment = new class($invoice->mollie_payment_id)
    {
        public string $status = 'paid';

        public object $amount;

        public object $amountRefunded;

        public function __construct(public string $id)
        {
            $this->amount = (object) ['value' => '240.00', 'currency' => 'EUR'];
            $this->amountRefunded = (object) ['value' => '12.00', 'currency' => 'EUR'];
        }

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) ['id' => 're_inflight', 'amount' => (object) ['value' => '12.00', 'currency' => 'EUR']]];
        }
    };

    try {
        expect(fn () => app(RefundHandler::class)->handle($payment, $billable))
            ->toThrow(RefundInProgressException::class);
    } finally {
        $lock->release();
    }

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0);

    // Once the holder is done the re-delivery books it as before.
    app(RefundHandler::class)->handle($payment, $billable);

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
});
