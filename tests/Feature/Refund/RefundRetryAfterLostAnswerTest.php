<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\RefundHandler;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * A refund line whose Mollie answer was lost is retried by RetryRefundLineJob — and by then
 * the refund may already be on the books, or still sit at Mollie where the idempotency key
 * no longer reaches it.
 *
 * Mollie calls the payment webhook as soon as the refund exists, so RefundHandler booked the
 * refund as "synced from Mollie dashboard". The retry then posted the line again: a replay of
 * the same key wrote a second credit note for the same refund id; a clamp to what was left,
 * or a retry after Mollie's one-hour key cache, created a second refund and paid the customer
 * twice; and a sync that had used up the invoice made the line fail "Nothing left to refund"
 * for seven days and dead-letter as "permanently failed", although the money had gone out.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

/**
 * A Mollie that behaves like the real one for refunds: a key it has seen within the hour
 * replays the stored refund, anything else creates a new one, and the refund list carries
 * the metadata each refund was created with.
 */
final class LostAnswerRefundMollie
{
    /** @var array<string, array{refund: object, at: \Carbon\CarbonInterface}> */
    public array $byKey = [];

    /** @var list<object> */
    public array $refunds = [];

    public int $posts = 0;

    public ?string $key = null;

    public bool $loseNextAnswer = false;

    public function install(): void
    {
        Mollie::shouldReceive('setIdempotencyKey')->andReturnUsing(function (?string $key) {
            $this->key = $key;

            return Mollie::getFacadeRoot();
        });

        Mollie::shouldReceive('send')->andReturnUsing(fn (object $request) => $this->answer($request));
    }

    private function answer(object $request): mixed
    {
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

        expect($request)->toBeInstanceOf(CreatePaymentRefundRequest::class);

        $this->posts++;
        $key = (string) $this->key;
        $now = BillingTime::nowUtc();

        if (isset($this->byKey[$key]) && $this->byKey[$key]['at']->diffInMinutes($now) < 60) {
            $refund = $this->byKey[$key]['refund'];
        } else {
            $read = fn (string $property) => (new ReflectionObject($request))->getProperty($property)->getValue($request);
            $amount = $read('amount');
            $metadata = $read('metadata');

            $refund = (object) [
                'id' => 're_'.(count($this->refunds) + 1),
                'status' => 'pending',
                'amount' => (object) ['value' => $amount->value, 'currency' => $amount->currency],
                'metadata' => $metadata === null ? null : (object) $metadata,
            ];

            $this->refunds[] = $refund;
            $this->byKey[$key] = ['refund' => $refund, 'at' => $now];
        }

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            throw new RuntimeException('cURL error 28: read timeout');
        }

        return $refund;
    }

    /** The payment as the webhook fetches it, listing what Mollie holds. */
    public function payment(string $paymentId): object
    {
        $refunds = fn (): array => $this->refunds;

        return new class($paymentId, $refunds)
        {
            public string $status = 'paid';

            public function __construct(public string $id, private Closure $refunds)
            {
            }

            /** @return list<object> */
            public function refunds(): array
            {
                return ($this->refunds)();
            }
        };
    }
}

function lostAnswerBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Lost Answer Ltd',
        'email' => uniqid('lost-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function lostAnswerInvoice(TestBillable $billable, int $net): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_lost_'),
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

function lostAnswerLine(BillingInvoice $invoice, int $net): ProrataLine
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

/** Run the retry the sweep would dispatch for the one stored line. */
function retryStoredRefundLine(TestBillable $billable): void
{
    $entry = $billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'][0] ?? null;
    expect($entry)->not->toBeNull();

    (new RetryRefundLineJob(
        $billable::class,
        $billable->getKey(),
        $entry['line'],
        (string) $entry['first_attempt_at'],
    ))->handle(app(InvoiceService::class));
}

/** @return list<string> */
function creditNotesCarrying(TestBillable $billable, string $refundId): array
{
    return BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->get()
        ->filter(fn (BillingInvoice $note): bool => collect((array) $note->line_items)
            ->contains(fn (array $line): bool => ($line['mollie_refund_id'] ?? null) === $refundId))
        ->map(fn (BillingInvoice $note): string => (string) $note->serial_number)
        ->values()
        ->all();
}

it('does not book a refund twice when the retry replays one the webhook already synced', function (): void {
    $billable = lostAnswerBillable();
    $invoice = lostAnswerInvoice($billable, 10000);
    $mollie = new LostAnswerRefundMollie;
    $mollie->install();

    // 40.00 net / 48.00 gross. Mollie creates re_1, the answer is lost.
    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [lostAnswerLine($invoice, 4000)], 'Plan change refund');

    // The payment webhook for the refund, once the lock is free.
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    retryStoredRefundLine($billable);

    expect(creditNotesCarrying($billable, 're_1'))->toHaveCount(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);
});

it('does not pay a second refund when the synced refund makes the retry clamp to a new amount', function (): void {
    $billable = lostAnswerBillable();
    $invoice = lostAnswerInvoice($billable, 6000);
    $mollie = new LostAnswerRefundMollie;
    $mollie->install();

    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [lostAnswerLine($invoice, 4000)], 'Plan change refund');

    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    // 20.00 net is left, so the retry would clamp to 24.00 gross — a new key.
    retryStoredRefundLine($billable);

    expect($mollie->posts)->toBe(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('settles the line instead of dead-lettering it when the synced refund used up the invoice', function (): void {
    $billable = lostAnswerBillable();
    $invoice = lostAnswerInvoice($billable, 4000);
    $mollie = new LostAnswerRefundMollie;
    $mollie->install();

    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [lostAnswerLine($invoice, 4000)], 'Plan change refund');

    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    retryStoredRefundLine($billable);

    // The money went out; there is nothing to retry and nothing to report as failed.
    expect($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([])
        ->and($mollie->posts)->toBe(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('adopts the refund Mollie holds instead of posting again once the key has expired', function (): void {
    $billable = lostAnswerBillable();
    $invoice = lostAnswerInvoice($billable, 10000);
    $mollie = new LostAnswerRefundMollie;
    $mollie->install();

    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [lostAnswerLine($invoice, 4000)], 'Plan change refund');

    // No webhook got through, and the retry ladder has moved past Mollie's one-hour cache.
    Carbon::setTestNow(BillingTime::nowUtc()->addHours(2));

    try {
        retryStoredRefundLine($billable);
    } finally {
        Carbon::setTestNow();
    }

    expect($mollie->posts)->toBe(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and(creditNotesCarrying($billable, 're_1'))->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);

    // The webhook that finally arrives recognises the refund.
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    expect(creditNotesCarrying($billable, 're_1'))->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('does not write a second credit note when a replayed refund id is already on the books', function (): void {
    $billable = lostAnswerBillable();
    $invoice = lostAnswerInvoice($billable, 10000);
    $mollie = new LostAnswerRefundMollie;
    $mollie->install();

    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [lostAnswerLine($invoice, 4000)], 'Plan change refund');

    // A refund Mollie lists without our metadata (created before the token was sent along):
    // the synced credit note carries the id and nothing else to recognise the line by.
    $mollie->refunds[0]->metadata = null;
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    // Within the hour: the retry's key replays re_1.
    retryStoredRefundLine($billable);

    expect(creditNotesCarrying($billable, 're_1'))->toHaveCount(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});
