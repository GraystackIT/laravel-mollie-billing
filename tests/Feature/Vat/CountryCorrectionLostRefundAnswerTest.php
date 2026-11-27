<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Enums\RefundReasonCode;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Vat\CountryMatchService;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A country correction refunds what remained paid at the old rate and charges it again at
 * the new one. Its own refund is told apart from one made before the mismatch by the credit
 * note's reason (BillingError + CORRECTION_REFUND_REASON).
 *
 * When the correction's refund call lost its answer (a read timeout after Mollie had created
 * the refund), resolve() threw and the mismatch stayed Pending. Mollie's payment webhook then
 * booked the refund as "synced from Mollie dashboard" — reason Other — and the next resolve
 * read that note as a refund made *before* the correction: nothing remained paid, so nothing
 * was charged again. The customer kept the whole refund and the period went unpaid.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

/**
 * Mollie for this flow: a refund is stored with the metadata it was created with (and its
 * answer can be lost), the refund list reads them back, and a payment create is recorded.
 */
final class CorrectionLostRefundMollie
{
    /** @var list<object> */
    public array $refunds = [];

    /** @var list<array{value: string, original_invoice_id: int}> */
    public array $charges = [];

    public bool $loseNextRefundAnswer = false;

    public function install(): void
    {
        Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(fn () => Mollie::getFacadeRoot());
        Mollie::shouldReceive('send')->andReturnUsing(fn (object $request) => $this->answer($request));
    }

    private function answer(object $request): mixed
    {
        $read = fn (string $property) => (new ReflectionObject($request))->getProperty($property)->getValue($request);

        if ($request instanceof GetPaginatedPaymentRefundsRequest) {
            return new class($this->refunds) implements IteratorAggregate
            {
                public ?object $_links = null;

                public function __construct(private array $items)
                {
                }

                public function getIterator(): Iterator
                {
                    return new ArrayIterator($this->items);
                }
            };
        }

        if ($request instanceof CreatePaymentRefundRequest) {
            $amount = $read('amount');
            $metadata = $read('metadata');

            $refund = (object) [
                'id' => 're_corr_'.(count($this->refunds) + 1),
                'status' => 'refunded',
                'amount' => (object) ['value' => $amount->value, 'currency' => $amount->currency],
                'metadata' => $metadata === null ? null : (object) $metadata,
            ];
            $this->refunds[] = $refund;

            if ($this->loseNextRefundAnswer) {
                $this->loseNextRefundAnswer = false;

                throw new RuntimeException('cURL error 28: read timeout');
            }

            return $refund;
        }

        if ($request instanceof CreatePaymentRequest) {
            $amount = $read('amount');
            $metadata = (array) $read('metadata');
            $this->charges[] = ['value' => $amount->value, 'original_invoice_id' => (int) ($metadata['original_invoice_id'] ?? 0)];

            return (object) ['id' => 'tr_corr_'.count($this->charges), 'status' => 'open'];
        }

        return (object) [];
    }

    /** The original payment as the webhook fetches it. */
    public function payment(string $paymentId): object
    {
        return (object) ['id' => $paymentId, 'status' => 'paid'];
    }
}

function correctionLostRefundBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'name' => 'Lost Correction',
        'email' => uniqid('lcr-').'@x.test',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'subscription_source' => SubscriptionSource::Local->value,
        'subscription_status' => SubscriptionStatus::Active,
        'mollie_customer_id' => 'cst_lcr',
        'mollie_mandate_id' => 'mdt_lcr',
    ])->save();

    return $b;
}

/** 2000 net at AT 20 % → 24.00 paid. */
function correctionLostRefundInvoice(TestBillable $billable): BillingInvoice
{
    $invoice = new BillingInvoice;
    $invoice->billable_type = $billable->getMorphClass();
    $invoice->billable_id = $billable->getKey();
    $invoice->mollie_payment_id = uniqid('tr_lcr_');
    $invoice->serial_number = uniqid('IN-');
    $invoice->invoice_kind = InvoiceKind::Subscription;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->country = 'AT';
    $invoice->currency = 'EUR';
    $invoice->amount_net = 2000;
    $invoice->amount_vat = 400;
    $invoice->amount_gross = 2400;
    $invoice->line_items = [[
        'kind' => 'plan', 'code' => 'pro', 'label' => 'Pro', 'quantity' => 1,
        'unit_price_net' => 2000, 'amount_net' => 2000,
        'vat_rate' => 20.0, 'vat_amount' => 400, 'amount_gross' => 2400,
    ]];
    $invoice->refunded_net = 0;
    $invoice->save();

    return $invoice;
}

it('charges the reissue when the correction refund was booked by the webhook after its answer was lost', function (): void {
    $mollie = new CorrectionLostRefundMollie;
    $mollie->install();

    $billable = correctionLostRefundBillable();
    $invoice = correctionLostRefundInvoice($billable);

    $service = app(CountryMatchService::class);
    $mismatch = $service->check($billable);
    expect($mismatch)->not->toBeNull();

    // Mollie creates the refund, the answer is lost: the resolve stops, still Pending.
    $mollie->loseNextRefundAnswer = true;
    expect(fn () => $service->resolve($billable->fresh(), $mismatch, 'DE'))->toThrow(RuntimeException::class);
    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Pending)
        ->and($mollie->refunds)->toHaveCount(1);

    // Mollie's payment webhook for that refund books it.
    app(RefundHandler::class)->handle($mollie->payment((string) $invoice->mollie_payment_id), $billable->fresh());

    $note = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    expect($invoice->fresh()->remainingRefundableNet())->toBe(0)
        // Booked as what it is: the correction's own refund.
        ->and($note->refund_reason_code)->toBe(RefundReasonCode::BillingError)
        ->and($note->refund_reason_text)->toBe(CountryMatchService::CORRECTION_REFUND_REASON);

    // The operator resolves again: nothing is refunded twice, and the reissue is charged in
    // full at the new rate — 20.00 + 19 %.
    $service->resolve($billable->fresh(), $mismatch->fresh(), 'DE');

    expect($mismatch->fresh()->status)->toBe(CountryMismatchStatus::Resolved)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and($mollie->charges)->toHaveCount(1)
        ->and($mollie->charges[0]['value'])->toBe('23.80')
        ->and($mollie->charges[0]['original_invoice_id'])->toBe((int) $invoice->id);
});

it('books a dashboard refund without the package metadata as before', function (): void {
    // A refund made in Mollie's dashboard carries no metadata: still "synced from Mollie
    // dashboard", reason Other — and so still a refund made before any correction.
    $mollie = new CorrectionLostRefundMollie;
    $mollie->install();

    $billable = correctionLostRefundBillable();
    $invoice = correctionLostRefundInvoice($billable);

    $mollie->refunds[] = (object) [
        'id' => 're_dashboard',
        'status' => 'refunded',
        'amount' => (object) ['value' => '24.00', 'currency' => 'EUR'],
        'metadata' => null,
    ];

    app(RefundHandler::class)->handle($mollie->payment((string) $invoice->mollie_payment_id), $billable);

    $note = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    expect($note->refund_reason_code)->toBe(RefundReasonCode::Other)
        ->and($note->refund_reason_text)->toBe('synced from Mollie dashboard');
});
