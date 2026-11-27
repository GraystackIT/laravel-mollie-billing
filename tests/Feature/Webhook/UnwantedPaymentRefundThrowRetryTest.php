<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Contracts\Billable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Jobs\RetryRefundLineJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * An automatic refund of an unwanted payment whose `InvoiceService::createRefund()` call
 * THROWS — as opposed to Mollie refusing the refund, which createRefund() catches and
 * queues itself. What throws there is everything around the Mollie call: the refund lock's
 * cache store, the credit note's write after Mollie has already paid out.
 *
 * It used to be caught, logged as "queued for retry", and not queued: the refund happened
 * only if Mollie called the webhook again. Now the line is parked in
 * `pending_refund_retries` — the queue RetryRefundLineJob drains — marked "outcome unknown",
 * so the retry asks Mollie by the refund's token before it posts anything.
 */

/** createRefund() throws before reaching Mollie, `$throws` times. */
final class ThrowingRefundInvoiceService extends InvoiceService
{
    public static int $throws = 0;

    public function createRefund(Billable $billable, array $refundLines, ?string $description = null): ?BillingInvoice
    {
        if (self::$throws > 0) {
            self::$throws--;

            throw new RuntimeException('Connection refused [tcp://cache:6379]');
        }

        return parent::createRefund($billable, $refundLines, $description);
    }
}

beforeEach(function (): void {
    longRunBoot();
    Notification::fake();
    config()->set('queue.default', 'database');
    Bus::fake([RetrySubscriptionActivationJob::class, RetryRefundLineJob::class]);
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    ThrowingRefundInvoiceService::$throws = 0;
    app()->singleton(InvoiceService::class, ThrowingRefundInvoiceService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
    ThrowingRefundInvoiceService::$throws = 0;
});

function utrRefundRequests(LongRunWorld $world): \Illuminate\Support\Collection
{
    return collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->values();
}

/**
 * Mollie lists a payment's refunds with the metadata they were created with — the fake
 * keeps the refunds, the requests keep the metadata.
 */
function utrListRefundsLikeMollie(LongRunWorld $world): void
{
    $world->intercept = function (object $request) use ($world) {
        if (! $request instanceof GetPaginatedPaymentRefundsRequest) {
            return null;
        }

        $paymentId = (string) longRunRequestProperty($request, 'paymentId');
        $sent = utrRefundRequests($world)
            ->filter(fn ($r) => (string) longRunRequestProperty($r, 'paymentId') === $paymentId)
            ->values();

        // A page of the list: iterable, no `next` link.
        return new \ArrayObject(array_map(function (object $refund, int $i) use ($sent): object {
            $refund->metadata = (object) (longRunRequestProperty($sent[$i], 'metadata') ?? []);

            return $refund;
        }, $world->refunds[$paymentId] ?? [], array_keys($world->refunds[$paymentId] ?? [])));
    };
}

function utrSibling(LongRunWorld $world, string $id): object
{
    $sibling = clone $world->payments['tr_lr_first'];
    $sibling->id = $id;

    return $world->payments[$id] = $sibling;
}

/** @return list<array<string, mixed>> */
function utrPending(LongRunWorld $world): array
{
    return array_values((array) ($world->billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries'] ?? []));
}

/** The hourly sweep, and the retry jobs it dispatches run to completion. */
function utrSweepAndRetry(LongRunWorld $world, string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    Bus::fake([RetrySubscriptionActivationJob::class, RetryRefundLineJob::class]);

    (new SweepPendingRetriesJob)->handle();

    // A rejected attempt throws so the queue backs off; the entry stays for the next sweep.
    Bus::dispatched(RetryRefundLineJob::class)->each(function (RetryRefundLineJob $job): void {
        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException) {
        }
    });
    $world->billable = $world->billable->fresh();
}

it('queues a refund whose createRefund() threw, and the retry posts it exactly once', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    utrListRefundsLikeMollie($world);

    Log::spy();
    ThrowingRefundInvoiceService::$throws = 1;
    longRunDeliver($world, utrSibling($world, 'tr_lr_second'), '2027-01-15 10:05:00');

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_second')->sole();
    $pending = utrPending($world);

    // Nothing reached Mollie, no credit note — and the line is queued, not forgotten.
    expect(utrRefundRequests($world))->toHaveCount(0)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and($pending)->toHaveCount(1)
        ->and((int) $pending[0]['line']['parent_invoice_id'])->toBe((int) $invoice->id)
        ->and((int) $pending[0]['line']['amount_gross'])->toBe(-3451)
        ->and($pending[0]['line']['refund_outcome_unknown'] ?? false)->toBeTrue()
        ->and($pending[0]['line']['refund_token'] ?? null)->toBe(UnwantedPaymentRefund::fullRefundToken($invoice))
        ->and($pending[0]['first_attempt_at'] ?? null)->not->toBeNull();

    // The log says what happened.
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'queued for retry'))->once();

    // The automatic way is still running: the customer is told, no operator is.
    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // The hourly sweep picks it up.
    utrSweepAndRetry($world, '2027-01-15 11:00:00');

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    $refunds = utrRefundRequests($world);

    expect($refunds)->toHaveCount(1)
        ->and((string) longRunRequestProperty($refunds[0], 'paymentId'))->toBe('tr_lr_second')
        ->and(longRunRequestProperty($refunds[0], 'amount')->value)->toBe('34.51')
        ->and((int) $creditNote->amount_gross)->toBe(-3451)
        ->and((int) $creditNote->amount_net)->toBe(-(int) $invoice->amount_net)
        ->and((int) $creditNote->amount_vat)->toBe(-(int) $invoice->amount_vat)
        ->and($creditNote->line_items[0]['mollie_refund_id'] ?? null)->toStartWith('re_longrun_')
        ->and((int) $invoice->fresh()->refunded_net)->toBe((int) $invoice->amount_net)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(0)
        ->and(utrPending($world))->toBe([]);

    // Again: the sweep, and Mollie's webhook. Nothing more.
    utrSweepAndRetry($world, '2027-01-15 12:00:00');
    longRunDeliver($world, $world->payments['tr_lr_second'], '2027-01-15 12:05:00');

    expect(utrRefundRequests($world))->toHaveCount(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
});

it('does not refund twice when Mollie created the refund and the credit note could not be written', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    utrListRefundsLikeMollie($world);

    // Mollie pays out; writing the credit note then fails once.
    $failures = 1;
    BillingInvoice::creating(function (BillingInvoice $invoice) use (&$failures): void {
        if ($invoice->invoice_kind === InvoiceKind::Refund && $failures-- > 0) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    });

    longRunDeliver($world, utrSibling($world, 'tr_lr_second'), '2027-01-15 10:05:00');

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_second')->sole();

    // The refund is out at Mollie, its credit note is not written by this run — the line
    // is queued, marked "outcome unknown". (The fake's payment already lists the refund in
    // this same delivery, so RefundHandler may book it as a refund it did not post.)
    expect(utrRefundRequests($world))->toHaveCount(1)
        ->and(utrPending($world))->toHaveCount(1)
        ->and(utrPending($world)[0]['line']['refund_outcome_unknown'] ?? false)->toBeTrue()
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBeLessThanOrEqual(1);

    // More than an hour later: Mollie's idempotency memory has forgotten the key. The retry
    // must find the refund by its token instead of posting a second one.
    $world->idempotency->honour = false;
    utrSweepAndRetry($world, '2027-01-15 13:00:00');

    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect(utrRefundRequests($world))->toHaveCount(1)
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof GetPaginatedPaymentRefundsRequest))->not->toBeEmpty()
        ->and((int) $creditNote->amount_gross)->toBe(-3451)
        ->and(json_encode($creditNote->line_items))->toContain($world->refunds['tr_lr_second'][0]->id)
        ->and($invoice->fresh()->remainingRefundableNet())->toBe(0)
        ->and(utrPending($world))->toBe([]);

    expect((int) $invoice->fresh()->refunded_net)->toBe((int) $invoice->amount_net);
});

it('tells an admin once when the queued refund has used up its retry window', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    utrListRefundsLikeMollie($world);

    ThrowingRefundInvoiceService::$throws = 1;
    longRunDeliver($world, utrSibling($world, 'tr_lr_second'), '2027-01-15 10:05:00');

    expect(utrPending($world))->toHaveCount(1);

    // Mollie refuses the refund on every attempt for a week.
    $world->refuse[CreatePaymentRefundRequest::class] = PHP_INT_MAX;
    utrSweepAndRetry($world, '2027-01-16 10:00:00');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    utrSweepAndRetry($world, '2027-01-23 10:00:00');

    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
    expect(utrPending($world))->toBe([])
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['pending_refund_retries_dead_letter'] ?? [])->toHaveCount(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0);

    // The dead letter is final: no new chain, no second mail.
    utrSweepAndRetry($world, '2027-01-23 11:00:00');
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});

it('queues the refund of a payment whose activation failed for good when createRefund() threw', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = PHP_INT_MAX);
    utrListRefundsLikeMollie($world);

    ThrowingRefundInvoiceService::$throws = 1;
    Carbon::setTestNow(Carbon::parse('2027-01-15 17:00:00', 'UTC'));
    (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))
        ->failed(new RuntimeException('Mollie is unavailable'));
    $world->billable = $world->billable->fresh();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_first')->sole();

    expect(utrRefundRequests($world))->toHaveCount(0)
        ->and(utrPending($world))->toHaveCount(1)
        ->and((int) utrPending($world)[0]['line']['parent_invoice_id'])->toBe((int) $invoice->id);

    utrSweepAndRetry($world, '2027-01-15 18:00:00');

    expect(utrRefundRequests($world))->toHaveCount(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole()->amount_gross)->toBe(-(int) $invoice->amount_gross)
        ->and(utrPending($world))->toBe([]);
});
