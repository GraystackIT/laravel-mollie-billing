<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\DuplicatePaymentReceived;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Webhook\UnwantedPaymentRefund;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * A second first payment for a billable whose subscription is already running is money
 * nobody wants. flows.md §13: every payment becomes an invoice, a payment nobody wants
 * becomes that invoice's credit note — so it is booked and refunded through
 * UnwantedPaymentRefund, never kept without a document. Two ways in:
 *
 *  - it reaches the activation gate after another payment activated (ActivationGate::Duplicate);
 *  - its own activation failed, another payment activated meanwhile, and the retry job
 *    for it finds the subscription running — it has to know WHICH payment activated it.
 */

beforeEach(function (): void {
    longRunBoot();
    Notification::fake();
    config()->set('queue.default', 'database');
    Bus::fake([RetrySubscriptionActivationJob::class]);
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
});

/** A sibling checkout tab's payment for the same order, paid too. */
function dupRefundSibling(LongRunWorld $world, string $id): object
{
    $sibling = clone $world->payments['tr_lr_first'];
    $sibling->id = $id;

    return $world->payments[$id] = $sibling;
}

function dupRefundRefundRequests(LongRunWorld $world): \Illuminate\Support\Collection
{
    return collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->values();
}

/** @return list<string> */
function dupRefundListen(string $event): \ArrayObject
{
    $seen = new \ArrayObject;
    Event::listen($event, function (object $e) use ($seen): void {
        $seen[] = $e;
    });

    return $seen;
}

function dupRefundRetry(LongRunWorld $world, string $paymentId, string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    app()->call([new RetrySubscriptionActivationJob($paymentId, TestBillable::class, $world->billable->getKey()), 'handle']);
    $world->billable = $world->billable->fresh();
}

it('books and refunds a second first payment that reaches the gate after the activation — once', function (): void {
    $duplicates = dupRefundListen(DuplicatePaymentReceived::class);
    $refunded = dupRefundListen(UnwantedPaymentRefunded::class);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    $second = dupRefundSibling($world, 'tr_lr_second');
    longRunDeliver($world, $second, '2027-01-15 10:05:00');

    $invoice = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_second')->sole();
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    $refunds = dupRefundRefundRequests($world);

    expect((int) $invoice->amount_gross)->toBe(3451)
        ->and(UnwantedPaymentRefund::isUnwanted($invoice))->toBeTrue()
        ->and((int) $creditNote->amount_gross)->toBe(-3451)
        ->and((int) $creditNote->amount_net)->toBe(-(int) $invoice->amount_net)
        ->and((int) $creditNote->amount_vat)->toBe(-(int) $invoice->amount_vat)
        ->and($refunds)->toHaveCount(1)
        ->and((string) longRunRequestProperty($refunds[0], 'paymentId'))->toBe('tr_lr_second')
        ->and(longRunRequestProperty($refunds[0], 'amount')->value)->toBe('34.51');

    // The subscription the first payment bought is untouched.
    expect($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun')
        ->and($world->billable->getBillingSubscriptionMeta()['activated_by_payment_id'] ?? null)->toBe('tr_lr_first');

    // Apps still hear about the duplicate; the refund is announced with its own reason.
    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]->paymentId)->toBe('tr_lr_second')
        ->and($refunded)->toHaveCount(1)
        ->and($refunded[0]->reason)->toBe('duplicate_payment');

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // The customer is told why, in both languages.
    foreach (['en', 'de'] as $locale) {
        expect(__('billing::notifications.unwanted_payment_refunded.reasons.duplicate_payment', [], $locale))
            ->not->toStartWith('billing::');
    }

    // Mollie re-delivers — once with the refund on the payment, once more unchanged.
    longRunDeliver($world, $world->payments['tr_lr_second'], '2027-01-15 10:06:00');
    longRunDeliver($world, $world->payments['tr_lr_second'], '2027-01-15 10:07:00');

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_second')->count())->toBe(1)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(dupRefundRefundRequests($world))->toHaveCount(1)
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($duplicates)->toHaveCount(1)
        ->and($refunded)->toHaveCount(1);

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);
});

it('refunds the booked payment whose activation is retried after another payment activated', function (string $how): void {
    $duplicates = dupRefundListen(DuplicatePaymentReceived::class);
    $refunded = dupRefundListen(UnwantedPaymentRefunded::class);

    // Payment A is booked, its Mollie subscription cannot be created: the retry is queued.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);

    expect(BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_first')->exists())->toBeTrue()
        ->and($world->billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_first');

    // Payment B, from a second tab, settles in the meantime and activates.
    longRunDeliver($world, dupRefundSibling($world, 'tr_lr_second'), '2027-01-15 10:00:30');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    if ($how === 'retry') {
        dupRefundRetry($world, 'tr_lr_first', '2027-01-15 10:01:00');
    } else {
        Carbon::setTestNow(Carbon::parse('2027-01-15 17:22:00', 'UTC'));
        (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))
            ->failed(new RuntimeException('Mollie is unavailable'));
        $world->billable = $world->billable->fresh();
    }

    $invoiceA = BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_first')->sole();
    $creditNote = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();
    $refunds = dupRefundRefundRequests($world);

    expect((int) $creditNote->amount_gross)->toBe(-(int) $invoiceA->amount_gross)
        ->and($refunds)->toHaveCount(1)
        ->and((string) longRunRequestProperty($refunds[0], 'paymentId'))->toBe('tr_lr_first')
        ->and(longRunRequestProperty($refunds[0], 'amount')->value)->toBe('34.51')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        // B's subscription runs on, B's invoice stays.
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(BillingInvoice::query()->where('mollie_payment_id', 'tr_lr_second')->exists())->toBeTrue()
        ->and($duplicates)->toHaveCount(1)
        ->and($duplicates[0]->paymentId)->toBe('tr_lr_first')
        ->and($refunded)->toHaveCount(1)
        ->and($refunded[0]->reason)->toBe('duplicate_payment');

    // No operator mail: nothing is left for a person to do.
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);

    // The job again, its failure hook, and the webhook again: nothing more.
    dupRefundRetry($world, 'tr_lr_first', '2027-01-16 09:00:00');
    (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))->failed(null);
    longRunDeliver($world, $world->payments['tr_lr_first'], '2027-01-16 09:05:00');

    expect(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(1)
        ->and(dupRefundRefundRequests($world))->toHaveCount(1)
        ->and($duplicates)->toHaveCount(1)
        ->and($refunded)->toHaveCount(1)
        ->and($world->subscriptionsCreated)->toBe(1);

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 1);

    // The record that told the retry apart.
    expect($world->billable->fresh()->getBillingSubscriptionMeta()['activated_by_payment_id'] ?? null)->toBe('tr_lr_second');
})->with(['retry' => 'retry', 'retries spent' => 'failed']);

it('keeps the payment whose own activation the retry completed', function (): void {
    $duplicates = dupRefundListen(DuplicatePaymentReceived::class);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);

    dupRefundRetry($world, 'tr_lr_first', '2027-01-15 10:01:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingSubscriptionMeta()['activated_by_payment_id'] ?? null)->toBe('tr_lr_first')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY);

    // The run died after the activation and before it cleared its marker: the next run,
    // and the failure hook, find the subscription THIS payment activated.
    $meta = $world->billable->getBillingSubscriptionMeta();
    $meta[ActivationRetry::META_KEY] = ['tr_lr_first' => ['context' => 'first_payment', 'reason' => 'x', 'first_failed_at' => now()->toIso8601String()]];
    $world->billable->forceFill(['subscription_meta' => $meta])->save();

    dupRefundRetry($world, 'tr_lr_first', '2027-01-15 10:06:00');

    expect($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY);

    $world->billable->forceFill(['subscription_meta' => $meta])->save();
    (new RetrySubscriptionActivationJob('tr_lr_first', TestBillable::class, $world->billable->getKey()))->failed(null);

    expect($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and(dupRefundRefundRequests($world))->toHaveCount(0)
        ->and($duplicates)->toHaveCount(0)
        ->and($world->subscriptionsCreated)->toBe(1);

    Notification::assertSentOnDemandTimes(UnwantedPaymentRefundedNotification::class, 0);
    longRunAssertRenewal($world, longRunRenew($world), '34.51');
});

it('refunds nothing when it cannot tell which payment activated a subscription from before the record existed', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);
    longRunDeliver($world, dupRefundSibling($world, 'tr_lr_second'), '2027-01-15 10:00:30');

    // A subscription activated before this package version wrote the record.
    $meta = $world->billable->getBillingSubscriptionMeta();
    unset($meta['activated_by_payment_id']);
    $world->billable->forceFill(['subscription_meta' => $meta])->save();

    dupRefundRetry($world, 'tr_lr_first', '2027-01-15 10:01:00');

    expect($world->billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->count())->toBe(0)
        ->and(dupRefundRefundRequests($world))->toHaveCount(0);
});

it('stands a resumed activation down when another payment activated between its check and its claim', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);
    longRunDeliver($world, dupRefundSibling($world, 'tr_lr_second'), '2027-01-15 10:00:30');

    expect($world->subscriptionsCreated)->toBe(1);

    // The race, made deterministic: the resumed delivery's unlocked check still saw the
    // activation incomplete; by the time it holds the row lock, payment B has activated.
    app()->instance(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::class, new class extends \GraystackIT\MollieBilling\Services\Webhook\WebhookSupport
    {
        public int $calls = 0;

        public function activationCompleted(\GraystackIT\MollieBilling\Contracts\Billable $billable): bool
        {
            return ++$this->calls === 1 ? false : parent::activationCompleted($billable);
        }
    });

    Carbon::setTestNow(Carbon::parse('2027-01-15 10:01:00', 'UTC'));
    ActivationRetry::whileResuming(fn () => app(\GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController::class)
        ->replayDelivery($world->payments['tr_lr_first'], $world->billable->fresh()));

    $billable = $world->billable->fresh();

    // No second Mollie subscription over the one B bought.
    expect($world->subscriptionsCreated)->toBe(1)
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof CreateSubscriptionRequest)->count())->toBe(2)
        ->and($billable->getBillingSubscriptionMeta()['activated_by_payment_id'] ?? null)->toBe('tr_lr_second')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(\GraystackIT\MollieBilling\Services\Webhook\WebhookSupport::ACTIVATION_CLAIM_META_KEY);
});
