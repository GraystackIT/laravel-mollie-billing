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
 * A refund line whose Mollie answer was lost waits in `pending_refund_retries`. The payment
 * webhook books the refund it created (token stamped onto the credit note); if Mollie then
 * reports that refund `canceled` or `failed`, RefundHandler reverses the booking. The money
 * never left.
 *
 * The retry used to find the token on the historical credit note and settle the line on
 * it — no payout, and no retry left. A booking that a reversal has taken back settles
 * nothing: the line is owed, exactly as `refundCreatedWithToken()` already treats a
 * canceled refund it finds at Mollie.
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
final class ReversedRetryRefundMollie
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

function reversedRetryBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Reversed Retry Ltd',
        'email' => uniqid('revretry-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function reversedRetryInvoice(TestBillable $billable, int $net): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_revretry_'),
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

function reversedRetryLine(BillingInvoice $invoice, int $net): ProrataLine
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

function reversedRetryRunStoredLine(TestBillable $billable): void
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

/** Lost answer → webhook books re_1 → Mollie reports re_1 as `$status` → reversal. */
function reversedRetryBookAndReverse(TestBillable $billable, BillingInvoice $invoice, ReversedRetryRefundMollie $mollie, string $status): void
{
    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [reversedRetryLine($invoice, 4000)], 'Plan change refund');

    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);
    expect($invoice->fresh()->effectiveRefundedNet())->toBe(4000);

    $mollie->refunds[0]->status = $status;
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);
    expect($invoice->fresh()->effectiveRefundedNet())->toBe(0);
}

/** @return list<string> */
function reversedRetryLiveRefunds(ReversedRetryRefundMollie $mollie): array
{
    return array_values(array_map(
        static fn (object $r): string => (string) $r->id,
        array_filter($mollie->refunds, static fn (object $r): bool => ! in_array($r->status, ['failed', 'canceled'], true)),
    ));
}

it('posts the line again when the refund it was settled by is canceled — within the key hour', function (): void {
    $billable = reversedRetryBillable();
    $invoice = reversedRetryInvoice($billable, 10000);
    $mollie = new ReversedRetryRefundMollie;
    $mollie->install();

    reversedRetryBookAndReverse($billable, $invoice, $mollie, 'canceled');

    // The retry ladder's next step (minutes later): the key of the first attempt would
    // still replay the canceled refund.
    reversedRetryRunStoredLine($billable);

    expect(reversedRetryLiveRefunds($mollie))->toBe(['re_2'])
        ->and($mollie->refunds[1]->amount->value)->toBe('48.00')
        ->and($mollie->refunds[1]->metadata->refund_token)->toBe($mollie->refunds[0]->metadata->refund_token)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);

    // The webhook for re_2 recognises it; nothing is booked twice.
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    expect($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});

it('posts the line again when the refund it was settled by failed — after the key hour', function (): void {
    $billable = reversedRetryBillable();
    $invoice = reversedRetryInvoice($billable, 10000);
    $mollie = new ReversedRetryRefundMollie;
    $mollie->install();

    reversedRetryBookAndReverse($billable, $invoice, $mollie, 'failed');

    Carbon::setTestNow(BillingTime::nowUtc()->addHours(2));

    try {
        reversedRetryRunStoredLine($billable);
    } finally {
        Carbon::setTestNow();
    }

    expect(reversedRetryLiveRefunds($mollie))->toBe(['re_2'])
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? [])->toBe([]);
});

it('still settles the line on a booking that stands', function (): void {
    $billable = reversedRetryBillable();
    $invoice = reversedRetryInvoice($billable, 10000);
    $mollie = new ReversedRetryRefundMollie;
    $mollie->install();

    $mollie->loseNextAnswer = true;
    app(InvoiceService::class)->createRefund($billable, [reversedRetryLine($invoice, 4000)], 'Plan change refund');
    app(RefundHandler::class)->handle($mollie->payment($invoice->mollie_payment_id), $billable);

    reversedRetryRunStoredLine($billable);

    expect($mollie->posts)->toBe(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000);
});
