<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Exceptions\RefundInProgressException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Models\BillingVatValidation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A plan-change refund and its own refund webhook must not both book the same money.
 *
 * InvoiceService::createRefund() posts its refunds at Mollie line by line and only writes
 * the credit note — the thing that carries `mollie_refund_id` — after the LAST line's
 * call. Mollie calls the payment webhook as soon as a refund exists. createRefund() did
 * not take the `billing:invoice-refund:{id}` lock RefundHandler checks, so a webhook
 * arriving while a later line was still in flight booked the first refund as "synced from
 * Mollie dashboard" — and createRefund() then booked it again.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

function raceRefundLine(BillingInvoice $invoice, int $index, string $kind, int $net): ProrataLine
{
    return new ProrataLine(
        originalInvoice: $invoice,
        originalLineItemIndex: $index,
        kind: $kind,
        code: $kind === 'plan' ? 'pro' : null,
        label: ucfirst($kind),
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

it('books a plan-change refund once when its refund webhook arrives while a later line is still being posted', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Race Ltd',
        'email' => uniqid('race-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();

    // Renewal invoice: plan 40.00 + seats 10.00 net, AT 20 % → 60.00 gross.
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_race',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 5000,
        'amount_vat' => 1000,
        'amount_gross' => 6000,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 4000, 'amount_net' => 4000, 'vat_rate' => 20.0, 'vat_amount' => 800, 'amount_gross' => 4800],
            ['kind' => 'seats', 'code' => null, 'label' => 'Seats', 'quantity' => 1, 'unit_price_net' => 1000, 'amount_net' => 1000, 'vat_rate' => 20.0, 'vat_amount' => 200, 'amount_gross' => 1200],
        ],
    ])->refresh();

    // The refund webhook Mollie sends once re_1 exists: the original payment, now listing re_1.
    $webhookPayment = new class
    {
        public string $id = 'tr_race';

        public string $status = 'paid';

        /** @return array<int, object> */
        public function refunds(): array
        {
            return [(object) [
                'id' => 're_1',
                'status' => 'pending',
                'amount' => (object) ['value' => '24.00', 'currency' => 'EUR'],
            ]];
        }
    };

    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => Mollie::getFacadeRoot());

    $calls = 0;
    $refused = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$calls, &$refused, $webhookPayment, $billable) {
        expect($request)->toBeInstanceOf(CreatePaymentRefundRequest::class);
        $calls++;

        if ($calls === 2) {
            // While the second refund's HTTP call is in flight, Mollie delivers the
            // webhook for the first one. It is refused — the controller answers 500 and
            // Mollie re-delivers.
            try {
                app(RefundHandler::class)->handle($webhookPayment, $billable);
            } catch (RefundInProgressException) {
                $refused++;
            }
        }

        return (object) ['id' => 're_'.$calls];
    });

    // Downgrade refund: 20.00 net of the plan line (24.00 gross) and 10.00 net of seats (12.00 gross).
    app(InvoiceService::class)->createRefund($billable, [
        raceRefundLine($invoice, 0, 'plan', 2000),
        raceRefundLine($invoice, 1, 'seats', 1000),
    ], 'Plan change refund');

    expect($calls)->toBe(2)
        ->and($refused)->toBe(1);

    // The re-delivery, once the plan change has committed: the refund id is on record now.
    app(RefundHandler::class)->handle($webhookPayment, $billable);

    // re_1 must sit on exactly one credit note.
    $notesCarryingRe1 = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->get()
        ->filter(fn (BillingInvoice $note): bool => collect((array) $note->line_items)
            ->contains(fn (array $line): bool => ($line['mollie_refund_id'] ?? null) === 're_1'))
        ->count();

    // 30.00 net went back to the customer — not 50.00.
    $fresh = BillingInvoice::query()->findOrFail($invoice->id);
    expect($fresh->effectiveRefundedNet())->toBe(3000)
        ->and($fresh->remainingRefundableNet())->toBe(2000);

    expect($notesCarryingRe1)->toBe(1);
});

it('queues a plan-change refund line instead of posting it while another refund of the invoice is in flight', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Busy Ltd',
        'email' => uniqid('busy-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();

    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_busy',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 4000,
        'amount_vat' => 800,
        'amount_gross' => 4800,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 4000, 'amount_net' => 4000, 'vat_rate' => 20.0, 'vat_amount' => 800, 'amount_gross' => 4800],
        ],
    ])->refresh();

    // An admin refund of the same invoice is between its Mollie call and its booking.
    $held = Cache::lock('billing:invoice-refund:'.$invoice->id, 120);
    expect($held->get())->toBeTrue();

    Mollie::shouldReceive('setIdempotencyKey')->never();
    Mollie::shouldReceive('send')->never();

    $result = app(InvoiceService::class)->createRefund($billable, [raceRefundLine($invoice, 0, 'plan', 2000)], 'Plan change refund');

    $held->release();

    // Nothing was posted and nothing booked; the line waits for the retry job, which
    // re-reads the remaining balance when its turn comes.
    expect($result)->toBeNull()
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toHaveCount(1);
});

it('files a plan-change credit note under the validation its original invoice relied on', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Late VAT Ltd',
        'email' => uniqid('latevat-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();

    // Sold as a consumer: the invoice relied on no validation.
    $invoice = BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_latevat',
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 4000,
        'amount_vat' => 800,
        'amount_gross' => 4800,
        'vat_validation_id' => null,
        'serial_number' => 'IN-'.uniqid(),
        'period_start' => BillingTime::nowUtc()->subDays(5),
        'period_end' => BillingTime::nowUtc()->addDays(25),
        'line_items' => [
            ['kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1, 'unit_price_net' => 4000, 'amount_net' => 4000, 'vat_rate' => 20.0, 'vat_amount' => 800, 'amount_gross' => 4800],
        ],
    ])->refresh();

    // Afterwards the customer registers a VAT number.
    BillingVatValidation::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'vat_number' => 'ATU12345678',
        'country_code' => 'AT',
        'valid' => true,
        'vies_response' => [],
        'checked_at' => now(),
    ]);
    $billable->forceFill(['vat_number' => 'ATU12345678'])->save();
    expect($billable->fresh()->currentVatValidation())->not->toBeNull();

    Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => Mollie::getFacadeRoot());
    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 're_latevat']);

    $creditNote = app(InvoiceService::class)->createRefund($billable->fresh(), [raceRefundLine($invoice, 0, 'plan', 2000)], 'Plan change refund');

    expect($creditNote)->not->toBeNull()
        ->and($creditNote->vat_validation_id)->toBeNull();
});
