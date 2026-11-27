<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReferenceCleared;
use GraystackIT\MollieBilling\Events\MollieSubscriptionReplaced;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionReplacementJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionReplacer;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * Mollie refuses a new start date on a subscription it has charged (422 "The start date
 * cannot be changed when the subscription is already charged" — measured 17 November
 * 2026, tests/Sandbox/MollieChargedSubscriptionContractTest). Where the package has to move
 * the next charge of such a subscription, it replaces it: cancel, then create the same
 * subscription starting on the new date (MollieSubscriptionReplacer). The long-run world
 * plays Mollie with that rule.
 *
 * The case used throughout: a signup on 31 January 2027. Its first charge is 28 February
 * (the end of the first period), so Mollie's subscription starts on the 28th; the anchor
 * day is the 31st, and after the 28 February charge the next charge has to be 31 March.
 */

beforeEach(function (): void {
    longRunBoot();
    config()->set('mollie-billing.billable_model', TestBillable::class);
    Event::fake([PaymentAmountMismatch::class, MollieSubscriptionReplaced::class, MollieSubscriptionReferenceCleared::class]);
});

afterEach(fn () => Carbon::setTestNow());

/** The 31 January signup, under the cadence Mollie keeps no promise about: drift. */
function replacementWorld(): LongRunWorld
{
    $world = longRunStart('monthly', '2027-01-31 10:00:00', '34.51');
    $world->cadence = 'drift';

    expect($world->cadenceStart->toDateString())->toBe('2027-02-28');

    return $world;
}

/** @return list<object> */
function replacementRequestsSince(LongRunWorld $world, int $from): array
{
    return array_values(array_slice($world->requests, $from));
}

it('replaces a charged subscription to keep the anchor day: cancel first, then the same subscription from the new date', function (): void {
    $world = replacementWorld();
    $from = count($world->requests);

    $payment = longRunRenew($world);
    longRunAssertRenewal($world, $payment, '34.51');

    $sent = replacementRequestsSince($world, $from);
    $cancelAt = collect($sent)->search(fn ($r) => $r instanceof CancelSubscriptionRequest);
    $createAt = collect($sent)->search(fn ($r) => $r instanceof CreateSubscriptionRequest);
    $create = $sent[$createAt];

    // The PATCH was tried and refused; then cancel, then create — in that order.
    expect(collect($sent)->contains(fn ($r) => $r instanceof UpdateSubscriptionRequest && longRunRequestProperty($r, 'startDate') !== null))->toBeTrue()
        ->and($cancelAt)->not->toBeFalse()
        ->and($createAt)->toBeGreaterThan($cancelAt)
        ->and((string) longRunRequestProperty($sent[$cancelAt], 'subscriptionId'))->toBe('sub_longrun')
        // Everything kept, only the start moved — to the anchor day.
        ->and((string) longRunRequestProperty($create, 'startDate'))->toBe('2027-03-31')
        ->and(longRunRequestProperty($create, 'amount')->value)->toBe('34.51')
        ->and(longRunRequestProperty($create, 'interval'))->toBe('1 month')
        ->and(longRunRequestProperty($create, 'description'))->toBe('pro subscription')
        ->and(longRunRequestProperty($create, 'mandateId'))->toBe('mdt_longrun')
        ->and(longRunRequestProperty($create, 'webhookUrl'))->toBe('https://example.test/billing/webhook')
        ->and(longRunRequestProperty($create, 'metadata'))->toBe($world->subscriptionMetadata)
        ->and((string) longRunRequestProperty($create, 'metadata')['billable_id'])->toBe((string) $world->billable->getKey())
        // Stable across retries of this one replacement.
        ->and($world->idempotencyKeys)->toContain('subscription-replace:sub_longrun:2027-03-31:34.51:1 month')
        ->and($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-03-31');

    Event::assertDispatched(MollieSubscriptionReplaced::class, fn (MollieSubscriptionReplaced $e): bool => $e->previousSubscriptionId === 'sub_longrun'
        && $e->subscriptionId === 'sub_longrun_2'
        && $e->startDate->toDateString() === '2027-03-31'
        && $e->reason === 'anchor_pin');

    // The next renewal comes from the new subscription, on the 31st, and is booked as one.
    $next = longRunRenew($world);

    expect($next->subscriptionId)->toBe('sub_longrun_2')
        ->and(substr((string) $next->createdAt, 0, 10))->toBe('2027-03-31');
    longRunAssertRenewal($world, $next, '34.51');
});

it('leaves Mollie alone where its own next date already is the anchor day', function (): void {
    // Signed up on 31 December: Mollie's start date is 31 January, the anchor day. Under
    // "clamp" Mollie returns to the 31st after February by itself — nothing is sent, and
    // the subscription keeps its id.
    $world = longRunStart('monthly', '2026-12-31 10:00:00', '34.51');
    $world->cadence = 'clamp';

    foreach (range(1, 3) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');
    }

    expect(collect($world->requests)->contains(fn ($r) => $r instanceof UpdateSubscriptionRequest && longRunRequestProperty($r, 'startDate') !== null))->toBeFalse()
        ->and($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun')
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-04-30');
});

it('finishes a replacement interrupted between the cancel and the create, from the hourly sweep', function (): void {
    $world = replacementWorld();
    $world->refuse[CreateSubscriptionRequest::class] = 1;

    $payment = longRunRenew($world);
    longRunAssertRenewal($world, $payment, '34.51');

    $meta = $world->billable->fresh()->getBillingSubscriptionMeta();
    $marker = $meta[MollieSubscriptionReplacer::META_KEY] ?? null;

    // The old one is cancelled, nothing charges, and the row says what is still to do.
    expect($world->cancelled)->toBeTrue()
        ->and($marker)->toBeArray()
        ->and($marker['old_cancelled'])->toBeTrue()
        ->and($marker['start_date'])->toBe('2027-03-31')
        ->and($marker['amount'])->toBe(['currency' => 'EUR', 'value' => '34.51'])
        ->and($meta['mollie_subscription_id'])->toBe('sub_longrun');

    // In between, the gate neither drops the cancelled id nor lets a checkout start.
    expect(app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($world->billable->fresh()))->toBeTrue();
    app(MollieSubscriptionGate::class)->snapshot($world->billable->fresh());
    expect($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun');
    Event::assertNotDispatched(MollieSubscriptionReferenceCleared::class);

    Carbon::setTestNow(Carbon::parse('2027-03-02 11:00:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);

    $meta = $world->billable->fresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($meta['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->subscriptionsCreated)->toBe(2)
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-03-31');

    $world->billable = $world->billable->fresh();
    longRunAssertRenewal($world, longRunRenew($world), '34.51');
});

it('adopts the replacement whose create answer was lost instead of creating a second one', function (): void {
    $world = replacementWorld();
    $world->loseAnswer[CreateSubscriptionRequest::class] = 1;

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    // Mollie created it; the package never heard.
    expect($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->toHaveKey(MollieSubscriptionReplacer::META_KEY);

    Carbon::setTestNow(Carbon::parse('2027-03-02 11:00:00', 'UTC'));
    (new RetrySubscriptionReplacementJob(TestBillable::class, $world->billable->getKey()))
        ->handle(app(MollieSubscriptionReplacer::class));

    // The second create met "same description already exists" — only one can be active —
    // and the retry took over the one that is there.
    expect($world->subscriptionsCreated)->toBe(2)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY);
});

it('never starts a replacement today or earlier, even when the retry comes after the date', function (): void {
    $world = replacementWorld();
    $world->refuse[CreateSubscriptionRequest::class] = 1;

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    // The retry only gets through on 2 April: 31 March has passed, and the old
    // subscription — cancelled — collected nothing for it. A start of today would charge at
    // once; tomorrow is the earliest.
    Carbon::setTestNow(Carbon::parse('2027-04-02 15:00:00', 'UTC'));
    (new RetrySubscriptionReplacementJob(TestBillable::class, $world->billable->getKey()))
        ->handle(app(MollieSubscriptionReplacer::class));

    $create = collect($world->requests)->last(fn ($r) => $r instanceof CreateSubscriptionRequest);

    expect((string) longRunRequestProperty($create, 'startDate'))->toBe('2027-04-03');
});

it('keeps booking a payment of the replaced subscription, and its refund, after the replacement', function (): void {
    $world = replacementWorld();

    // SEPA: created on 28 February, settled three days later — after the subscription that
    // created it has been replaced by the renewal of… itself. Then refunded from the
    // dashboard.
    $payment = longRunCreateCharge($world, '3 days');
    longRunDeliver($world, $payment);
    longRunAssertRenewal($world, $payment, '34.51');

    expect($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($payment->subscriptionId)->toBe('sub_longrun');

    $world->refunds[$payment->id][] = (object) [
        'id' => 're_dashboard',
        'status' => 'refunded',
        'amount' => (object) ['value' => '10.00', 'currency' => 'EUR'],
    ];
    $payment->amountRefunded = (object) ['value' => '10.00', 'currency' => 'EUR'];

    longRunDeliver($world, $payment, '2027-03-05 10:00:00');

    $credit = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund)->sole();

    expect((int) $credit->amount_gross)->toBe(-1000)
        ->and($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('drops a pending replacement when the customer cancels before it is finished', function (): void {
    $world = replacementWorld();
    $world->refuse[CreateSubscriptionRequest::class] = 1;

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    // The customer cancels: the cancel targets the id on the row — the old one, which the
    // replacement already cancelled. Mollie answers 422 "has been cancelled"; that is done.
    Carbon::setTestNow(Carbon::parse('2027-03-01 09:00:00', 'UTC'));
    app(CancelSubscription::class)->handle($world->billable->fresh());

    expect($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('pending_subscription_cancel');

    app()->call([new SweepPendingRetriesJob, 'handle']);

    expect($world->subscriptionsCreated)->toBe(1)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
});

it('moves the charge of a charged subscription by an extension with a replacement, and keeps the PATCH for one not yet charged', function (): void {
    $world = replacementWorld();

    // Not charged yet (start 28 February): the PATCH is taken, the id stays.
    Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'UTC'));
    app(MollieSubscriptionPatcher::class)->setNextChargeDate($world->billable->fresh(), Carbon::parse('2027-03-05', 'UTC'));

    expect($world->subscriptionsCreated)->toBe(1)
        ->and($world->nextChargeAt()->toDateString())->toBe('2027-03-05');

    // Charged on 5 March; then extended by ten days: Mollie refuses the date, so the
    // subscription is replaced — once, with the extended date.
    $world->billable->forceFill(['subscription_meta' => array_diff_key($world->billable->fresh()->getBillingSubscriptionMeta(), ['next_charge_date_override' => true])])->save();
    $world->billable = $world->billable->fresh();
    longRunRenew($world);

    Carbon::setTestNow(Carbon::parse('2027-03-10 10:00:00', 'UTC'));
    $target = Carbon::parse($world->nextChargeAt()->toDateString(), 'UTC')->addDays(10);
    app(MollieSubscriptionPatcher::class)->setNextChargeDate($world->billable->fresh(), $target);

    expect($world->subscriptionsCreated)->toBe(2)
        ->and($world->nextChargeAt()->toDateString())->toBe($target->toDateString())
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2')
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['next_charge_date_override'] ?? null)->toBe($target->toIso8601String());

    Event::assertDispatched(MollieSubscriptionReplaced::class, fn (MollieSubscriptionReplaced $e): bool => $e->reason === 'next_charge_date');
});

it('tells the admins once when a replacement has not finished after 24 hours, and keeps retrying', function (): void {
    \Illuminate\Support\Facades\Notification::fake();
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn () => [new \Illuminate\Notifications\AnonymousNotifiable]);

    $world = replacementWorld();
    $world->refuse[CreateSubscriptionRequest::class] = PHP_INT_MAX;

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    $retry = fn () => (new RetrySubscriptionReplacementJob(TestBillable::class, $world->billable->getKey()))
        ->handle(app(MollieSubscriptionReplacer::class));

    // Called directly, not on a queue: a failure throws back.
    Carbon::setTestNow(Carbon::parse('2027-02-28 20:00:00', 'UTC'));
    expect($retry)->toThrow(RuntimeException::class);
    \Illuminate\Support\Facades\Notification::assertNothingSent();

    Carbon::setTestNow(Carbon::parse('2027-03-02 12:00:00', 'UTC'));
    expect($retry)->toThrow(RuntimeException::class);
    expect($retry)->toThrow(RuntimeException::class);

    \Illuminate\Support\Facades\Notification::assertSentTimes(\GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification::class, 1);

    // The marker stays: the sweep goes on, and once Mollie answers it is finished.
    $world->refuse[CreateSubscriptionRequest::class] = 0;
    $retry();

    expect($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['mollie_subscription_id'])->toBe('sub_longrun_2');
});

it('drops a pending replacement when the customer downgrades to a free plan before it is finished', function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]]],
    ]);

    $world = replacementWorld();
    $world->refuse[CreateSubscriptionRequest::class] = 1;

    longRunAssertRenewal($world, longRunRenew($world), '34.51');

    expect($world->billable->fresh()->getBillingSubscriptionMeta()[MollieSubscriptionReplacer::META_KEY]['old_cancelled'] ?? null)->toBeTrue();

    // The free downgrade cancels the id on the row — the old one, already cancelled by the
    // replacement: Mollie answers 422 "has been cancelled", which counts as done. The
    // billable becomes Local and Active.
    Carbon::setTestNow(Carbon::parse('2027-03-01 09:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)
        ->update($world->billable->fresh(), ['plan_code' => 'free', 'interval' => 'monthly', 'seats' => 1, 'addons' => []]);

    $billable = $world->billable->fresh();

    expect($billable->getBillingSubscriptionSource())->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionSource::Local->value)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    // The retry must not bring the paid subscription back for a customer on the free plan.
    Carbon::setTestNow(Carbon::parse('2027-03-01 11:00:00', 'UTC'));
    (new RetrySubscriptionReplacementJob(TestBillable::class, $world->billable->getKey()))
        ->handle(app(MollieSubscriptionReplacer::class));
    app()->call([new SweepPendingRetriesJob, 'handle']);

    $meta = $world->billable->fresh()->getBillingSubscriptionMeta();

    expect($world->subscriptionsCreated)->toBe(1)
        ->and($meta)->not->toHaveKey(MollieSubscriptionReplacer::META_KEY)
        ->and($meta['mollie_subscription_id'] ?? null)->toBeNull()
        ->and(app(MollieSubscriptionGate::class)->hasLiveMollieSubscription($world->billable->fresh()))->toBeFalse();
});
