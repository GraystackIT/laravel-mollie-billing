<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ProrataLine;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * RetryRefundLineJob runs again after a throw with the payload it was dispatched with. What
 * the attempts since have learned — how often Mollie refused the line, that an answer was
 * lost, the token the refund carries — lives on the stored `pending_refund_retries` entry.
 *
 * The job removed that entry before calling createRefund(), which re-appends a line Mollie
 * rejected but can also THROW (a credit note that cannot be written after Mollie paid out).
 * The entry was then gone: the next run used the old payload, skipped the token lookup and
 * sent a key Mollie no longer held — and paid the customer a second time.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');
});

/** Refund side of Mollie: a key seen within the hour replays, the list carries metadata. */
final class ThrowKeepsEntryRefundMollie
{
    /** @var array<string, array{refund: object, at: \Carbon\CarbonInterface}> */
    public array $byKey = [];

    /** @var list<object> */
    public array $refunds = [];

    public int $posts = 0;

    public ?string $key = null;

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
            return $this->byKey[$key]['refund'];
        }

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

        return $refund;
    }
}

function throwKeepsEntryBillable(): TestBillable
{
    /** @var TestBillable $billable */
    return TestBillable::create([
        'name' => 'Throw Keeps Entry Ltd',
        'email' => uniqid('throw-keeps-').'@x.test',
        'billing_country' => 'AT',
    ])->refresh();
}

function throwKeepsEntryInvoice(TestBillable $billable, int $net): BillingInvoice
{
    $vat = (int) round($net * 0.2);

    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => uniqid('tr_throw_keeps_'),
        'invoice_kind' => InvoiceKind::Subscription,
        'status' => InvoiceStatus::Paid,
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => $net,
        'amount_vat' => $vat,
        'amount_gross' => $net + $vat,
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
            'vat_amount' => $vat,
            'amount_gross' => $net + $vat,
        ]],
    ])->refresh();
}

function throwKeepsEntryLine(BillingInvoice $invoice, int $net): ProrataLine
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

/** @param array<string, mixed> $line */
function throwKeepsEntryStore(TestBillable $billable, array $line, string $firstAttemptAt): void
{
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['pending_refund_retries'] = [[
        'line' => $line,
        'error' => 'an earlier attempt',
        'first_attempt_at' => $firstAttemptAt,
    ]];
    $billable->forceFill(['subscription_meta' => $meta])->save();
}

/** @return list<array<string, mixed>> */
function throwKeepsEntryStored(TestBillable $billable): array
{
    return array_values((array) ($billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []));
}

function throwKeepsEntryCreditNotes(TestBillable $billable): int
{
    return BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->count();
}

it('keeps the stored entry when the booking throws after Mollie paid, and the next run adopts that refund', function (): void {
    $billable = throwKeepsEntryBillable();
    $invoice = throwKeepsEntryInvoice($billable, 10000);
    $mollie = new ThrowKeepsEntryRefundMollie;
    $mollie->install();

    $firstAttemptAt = BillingTime::nowUtc()->subDays(2)->toIso8601String();

    $line = throwKeepsEntryLine($invoice, 4000);
    $line->refundToken = 'tokthrow';
    $payload = $line->toArray();

    // Since the dispatch, an attempt was refused by Mollie: the stored entry knows of one
    // refusal and of an outcome to ask Mollie about. The payload knows neither.
    throwKeepsEntryStore($billable, [
        ...$payload,
        'refund_key_refusals' => 1,
        'refund_outcome_unknown' => true,
    ], $firstAttemptAt);

    $job = new RetryRefundLineJob($billable::class, $billable->getKey(), $payload, $firstAttemptAt);

    // Mollie creates the refund, then the credit note cannot be written.
    $failBooking = true;
    BillingInvoice::creating(function (BillingInvoice $note) use (&$failBooking): void {
        if ($failBooking && $note->invoice_kind === InvoiceKind::Refund) {
            throw new RuntimeException('SQLSTATE[HY000]: Lock wait timeout exceeded');
        }
    });

    expect(fn () => $job->handle(app(InvoiceService::class)))
        ->toThrow(RuntimeException::class, 'Lock wait timeout');

    expect($mollie->posts)->toBe(1)
        ->and(throwKeepsEntryCreditNotes($billable))->toBe(0);

    $stored = throwKeepsEntryStored($billable);
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['first_attempt_at'])->toBe($firstAttemptAt)
        ->and($stored[0]['line']['refund_token'])->toBe('tokthrow')
        ->and($stored[0]['line']['refund_key_refusals'] ?? 0)->toBe(1)
        ->and($stored[0]['line']['refund_outcome_unknown'] ?? false)->toBeTrue();

    // The queue runs the same job again, past Mollie's one-hour key cache.
    $failBooking = false;
    Carbon::setTestNow(BillingTime::nowUtc()->addHours(2));

    try {
        $job->handle(app(InvoiceService::class));
    } finally {
        Carbon::setTestNow();
    }

    expect($mollie->posts)->toBe(1)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and(throwKeepsEntryCreditNotes($billable))->toBe(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and(throwKeepsEntryStored($billable))->toBe([]);
});

it('takes the token from the stored entry when the payload predates it', function (): void {
    $billable = throwKeepsEntryBillable();
    $invoice = throwKeepsEntryInvoice($billable, 10000);
    $mollie = new ThrowKeepsEntryRefundMollie;
    $mollie->install();

    $firstAttemptAt = BillingTime::nowUtc()->subDays(1)->toIso8601String();

    // Dispatched after a first failure that never reached Mollie (the refund lock was
    // held): no token yet. A later attempt minted one, and its answer was lost — Mollie
    // holds the refund.
    $payload = throwKeepsEntryLine($invoice, 4000)->toArray();
    expect($payload['refund_token'] ?? null)->toBeNull();

    throwKeepsEntryStore($billable, [
        ...$payload,
        'refund_token' => 'toklost1',
        'refund_outcome_unknown' => true,
    ], $firstAttemptAt);

    $mollie->refunds[] = (object) [
        'id' => 're_1',
        'status' => 'pending',
        'amount' => (object) ['value' => '48.00', 'currency' => 'EUR'],
        'metadata' => (object) ['refund_token' => 'toklost1'],
    ];

    (new RetryRefundLineJob($billable::class, $billable->getKey(), $payload, $firstAttemptAt))
        ->handle(app(InvoiceService::class));

    expect($mollie->posts)->toBe(0)
        ->and($mollie->refunds)->toHaveCount(1)
        ->and(throwKeepsEntryCreditNotes($billable))->toBe(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(4000)
        ->and(throwKeepsEntryStored($billable))->toBe([]);
});
