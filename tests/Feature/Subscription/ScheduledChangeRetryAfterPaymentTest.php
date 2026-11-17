<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Events\PlanChangeFailed;
use GraystackIT\MollieBilling\Events\PlanChangePending;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\PlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\ScheduledChangeNotAppliedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/**
 * A scheduled change that fails AFTER its prorata payment was created.
 *
 * The payment exists at Mollie and `pending_prorata_change` names it, but `update()` threw
 * before it returned (an app listener on `PlanChangePending`, a failed write), so
 * `scheduled_change` is still stored and the next night tries again.
 *
 *  1. A later attempt creates no second payment — the first one is still in flight.
 *  2. When the change is dropped, the change waiting for that payment is withdrawn with
 *     it: nothing is left that a late payment could still apply.
 *  3. A payment that settles after the drop is returned in full, the plan stays, the
 *     customer is told about the refund.
 *  4. A re-delivered webhook does none of that twice.
 */
beforeEach(function (): void {
    World::boot();

    $this->admin = TestBillable::create(['name' => 'Operator', 'email' => 'operator-after-payment@x.test']);
    MollieBilling::notifyAdminUsing(fn (): array => [$this->admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

/** Pro → Enterprise, scheduled for the period end (1 Feb). */
function retryAfterPaymentScheduledUpgrade(): TestBillable
{
    $billable = World::subscription('none', 'pro', 2, []);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    return $billable->refresh();
}

/**
 * Night one, 20 January: the payment is created, then an app listener on
 * `PlanChangePending` throws. Returns the id of the payment that is now in flight.
 */
function retryAfterPaymentFirstNightFails(TestBillable $billable, bool $onRequest = false): string
{
    $throw = true;
    Event::listen(PlanChangePending::class, function () use (&$throw): void {
        if ($throw) {
            $throw = false;

            throw new RuntimeException('The app listener is down');
        }
    });

    test()->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));

    if ($onRequest) {
        expect(fn () => app(ScheduleSubscriptionChange::class)->apply($billable, onRequest: true))
            ->toThrow(RuntimeException::class, 'The app listener is down');
    } else {
        app(ScheduleSubscriptionChange::class)->apply($billable);
    }

    return (string) ($billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
}

/** Nights two and three: the change is given up on the third attempt. */
function retryAfterPaymentRunUntilDropped(TestBillable $billable): void
{
    foreach (['2026-01-21', '2026-01-22'] as $night) {
        test()->travelTo(Carbon::parse($night.' 02:00:00', 'UTC'));
        app(ScheduleSubscriptionChange::class)->apply($billable);
    }

    $billable->refresh();
}

/** How many payments Mollie was asked to create. */
function retryAfterPaymentPaymentsCreated(): int
{
    return count(array_filter(World::$sent, fn (object $r): bool => $r instanceof CreatePaymentRequest));
}

/**
 * What the payment of 20 January is worth, from the rule: the new plan for the days
 * left in the period (20–31 January = 12 of 31), plus 20 % Austrian VAT.
 *
 * @return array{0: int, 1: int, 2: string} net, gross, Mollie value
 */
function retryAfterPaymentExpectedCharge(): array
{
    $net = (int) round(20000 * 12 / 31);
    $gross = $net + (int) round($net * 0.20);

    return [$net, $gross, number_format($gross / 100, 2, '.', '')];
}

/** @return array<string, mixed> */
function retryAfterPaymentPendingKeys(TestBillable $billable): array
{
    return array_intersect_key(
        $billable->refresh()->getBillingSubscriptionMeta(),
        array_flip(['pending_prorata_change', 'pending_plan_change', 'prorata_pending_payment_id']),
    );
}

it('creates no second payment on the following nights while the first one is in flight', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [, , $value] = retryAfterPaymentExpectedCharge();

    // Night one left the payment and the change that waits for it — and the scheduled
    // change, counted once.
    expect($paymentId)->not->toBe('')
        ->and(World::$payments[$paymentId]['value'])->toBe($value)
        ->and(retryAfterPaymentPaymentsCreated())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['charge_payment_id'] ?? null)->toBe($paymentId)
        ->and(ScheduleSubscriptionChange::retryState($billable)['payment_id'] ?? null)->toBe($paymentId)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro');

    $this->travelTo(Carbon::parse('2026-01-21 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    // What stops it: the validator refuses a change while another waits for its payment.
    expect(retryAfterPaymentPaymentsCreated())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(2)
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['charge_payment_id'] ?? null)->toBe($paymentId)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe($paymentId);

    Notification::assertNothingSentTo($this->admin);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('is carried out once — by its payment — when that settles before the next attempt', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [$net, $gross] = retryAfterPaymentExpectedCharge();

    World::settle($billable);
    $billable->refresh();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and((int) $invoice->amount_net)->toBe($net)
        ->and((int) $invoice->amount_gross)->toBe($gross)
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([]);

    $invoices = BillingInvoice::query()->count();

    // Night two finds the stored change already in place: nothing to charge, nothing to
    // count, nothing to tell anyone.
    $this->travelTo(Carbon::parse('2026-01-21 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect(retryAfterPaymentPaymentsCreated())->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe($invoices)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->scheduled_change_at)->toBeNull()
        ->and(ScheduleSubscriptionChange::droppedNotice($billable))->toBeNull();

    Notification::assertNothingSentTo($this->admin);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('withdraws the change that waits for the payment when the scheduled change is dropped', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);

    retryAfterPaymentRunUntilDropped($billable);

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['reason'] ?? null)->toBe('attempts_exhausted')
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['attempts'] ?? null)->toBe(3)
        // Nothing is left that a late payment could apply …
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        // … and the payment Mollie would not cancel is remembered for what it was.
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null)->toBe($paymentId)
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)->toBe('not_cancelable')
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(retryAfterPaymentPaymentsCreated())->toBe(1)
        // The screens say that the payment comes back.
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeTrue();

    // And so does the mail.
    Notification::assertSentToTimes($billable, ScheduledChangeNotAppliedNotification::class, 1);
    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->reason === 'attempts_exhausted' && $n->paymentOnItsWay === true);
    Notification::assertSentToTimes($this->admin, AdminPlanChangeFailedNotification::class, 1);
});

it('says in the mail, in both languages, that the payment on its way is refunded', function (string $locale) {
    app()->setLocale($locale);
    $billable = retryAfterPaymentScheduledUpgrade();

    $lines = fn (bool $onItsWay): array => (new ScheduledChangeNotAppliedNotification($billable, 'enterprise', 'attempts_exhausted', $onItsWay))
        ->toMail($billable)->introLines;
    $sentence = trans('billing::notifications.scheduled_change_not_applied.payment_returned');

    expect($sentence)->not->toContain('billing::')
        ->and($lines(true))->toContain($sentence)
        ->and($lines(false))->not->toContain($sentence)
        ->and(trans('billing::portal.scheduled_change_dropped_payment'))->not->toContain('billing::')
        ->and(trans('billing::admin.scheduled_changes.payment_in_flight', ['payment' => 'tr_x']))->toContain('tr_x');
})->with(['en', 'de']);

it('returns a payment in full that settles after the drop, leaves the plan and tells the customer — once', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [$net, $gross, $value] = retryAfterPaymentExpectedCharge();

    retryAfterPaymentRunUntilDropped($billable);

    $held = World::$heldValue;
    $collected = World::netCollected($billable);
    Event::fake([UnwantedPaymentRefunded::class]);

    $this->travelTo(Carbon::parse('2026-01-24 10:00:00', 'UTC'));
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    // Mollie delivers it again.
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    $billable->refresh();

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->first();
    $creditNotes = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', InvoiceKind::Refund)
        ->get()
        ->filter(fn (BillingInvoice $note): bool => (int) ($note->line_items[0]['parent_invoice_id'] ?? 0) === (int) $invoice?->id);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        // Pro, as it was before the change was announced.
        ->and(World::$heldValue)->toBe($held)->toBe('60.00')
        ->and($invoice)->not->toBeNull()
        ->and((int) $invoice->amount_net)->toBe($net)
        ->and((int) $invoice->amount_gross)->toBe($gross)
        ->and($creditNotes)->toHaveCount(1)
        ->and((int) $creditNotes->first()->amount_net)->toBe(-$net)
        ->and((int) $creditNotes->first()->amount_gross)->toBe(-$gross)
        ->and(World::netCollected($billable))->toBe($collected)
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe([$value])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull()
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        // Returned — the screen stops announcing it.
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeFalse();

    Event::assertDispatchedTimes(UnwantedPaymentRefunded::class, 1);
    Event::assertDispatched(UnwantedPaymentRefunded::class, fn ($e): bool => $e->reason === 'change_cancelled' && $e->paymentId === $paymentId);
    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
    Notification::assertSentTo($billable, UnwantedPaymentRefundedNotification::class, fn ($n): bool => $n->amountGross === $gross && $n->reason === 'change_cancelled');
    Notification::assertSentToTimes($billable, ScheduledChangeNotAppliedNotification::class, 1);
    // One admin mail: the change was given up. None about the payment.
    Notification::assertSentToTimes($this->admin, AdminPlanChangeFailedNotification::class, 1);
});

it('cancels the payment at Mollie when Mollie still allows it, and keeps no marker', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    World::$cancelable[$paymentId] = true;

    retryAfterPaymentRunUntilDropped($billable);

    $cancelled = array_values(array_filter(World::$sent, fn (object $r): bool => $r instanceof CancelPaymentRequest));

    expect($cancelled)->toHaveCount(1)
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull()
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeFalse();

    // Nothing is on its way, so the mail does not promise a refund.
    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->paymentOnItsWay === false);

    // Mollie reports the cancelled payment: nothing to do, nobody to tell.
    Event::fake([PlanChangeFailed::class]);
    World::deliver($billable, $paymentId, 'canceled', World::$payments[$paymentId]['value'], ['type' => 'prorata_charge']);

    Event::assertNotDispatched(PlanChangeFailed::class);
    Notification::assertNotSentTo($billable, PlanChangeFailedNotification::class);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->toBeNull();
});

it('keeps no marker for a payment that had already failed at Mollie when the change was dropped', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    // Failed at Mollie; the webhook saying so has not arrived yet.
    World::$paymentStatus[$paymentId] = 'failed';

    retryAfterPaymentRunUntilDropped($billable);

    expect(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull();

    // The late webhook: the customer was told the change is off — not also that it failed.
    Event::fake([PlanChangeFailed::class]);
    World::deliver($billable, $paymentId, 'failed', World::$payments[$paymentId]['value'], ['type' => 'prorata_charge']);

    Event::assertNotDispatched(PlanChangeFailed::class);
    Notification::assertNotSentTo($billable, PlanChangeFailedNotification::class);
    Notification::assertSentToTimes($billable, ScheduledChangeNotAppliedNotification::class, 1);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro');
});

it('tries again with a new payment after the first one was declined', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $first = retryAfterPaymentFirstNightFails($billable);

    // Declined on the same day: the waiting change is cleared by the webhook, the stored
    // change stays for the next night.
    World::settle($billable, 'failed');
    $billable->refresh();

    expect(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(1);

    $this->travelTo(Carbon::parse('2026-01-21 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $second = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    // 21–31 January = 11 of 31 days of Enterprise.
    $net = (int) round(20000 * 11 / 31);
    $gross = $net + (int) round($net * 0.20);

    expect($second)->not->toBe($first)
        ->and(retryAfterPaymentPaymentsCreated())->toBe(2)
        ->and(World::$payments[$second]['value'])->toBe(number_format($gross / 100, 2, '.', ''))
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $second)->firstOrFail()->amount_gross)->toBe($gross)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $first)->exists())->toBeFalse();
});

it('withdraws the waiting change too when the customer cancels the scheduled change themselves', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [, $gross, $value] = retryAfterPaymentExpectedCharge();

    app(UpdateSubscription::class)->cancelScheduledChange($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null)->toBe($paymentId);

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe([$value]);

    Notification::assertSentTo($billable, UnwantedPaymentRefundedNotification::class, fn ($n): bool => $n->amountGross === $gross);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('does not touch a different change that is waiting for its own payment when the scheduled one is dropped', function (): void {
    // A change scheduled for the period end, and — the same day — an immediate seat
    // change whose payment is still in flight when the scheduled one comes due.
    $billable = World::subscription('none', 'pro', 2, []);
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);
    World::change($billable->refresh(), ['seats' => 3, 'apply_at' => 'immediate'], settle: null);

    $seatPayment = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    // Pulled forward to the nightly run on three nights in a row: refused each time
    // because the seat change is still waiting.
    foreach (['2026-01-20', '2026-01-21', '2026-01-22'] as $night) {
        $this->travelTo(Carbon::parse($night.' 02:00:00', 'UTC'));
        app(ScheduleSubscriptionChange::class)->apply($billable);
    }

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe($seatPayment)
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull()
        ->and($billable->getBillingSeatCount())->toBe(3);

    // The seat payment settles and is booked, not returned.
    World::settle($billable);

    expect(World::$refunds[$seatPayment] ?? [])->toBe([])
        ->and(BillingInvoice::query()->where('mollie_payment_id', $seatPayment)->exists())->toBeTrue()
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3);
});

it('keeps the change and counts nothing when "apply now" fails after the payment exists — and still knows its payment', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable, onRequest: true);
    [, , $value] = retryAfterPaymentExpectedCharge();

    $change = $billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null;

    expect($change)->not->toBeNull()
        ->and($change['apply_attempts'] ?? 0)->toBe(0)
        ->and($change['charge_payment_id'] ?? null)->toBe($paymentId)
        ->and(ScheduleSubscriptionChange::retryState($billable))->toBeNull()
        ->and(retryAfterPaymentPaymentsCreated())->toBe(1);

    // A second click creates no second payment either.
    expect(fn () => app(ScheduleSubscriptionChange::class)->apply($billable, onRequest: true))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException::class);

    expect(retryAfterPaymentPaymentsCreated())->toBe(1)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? 0)->toBe(0);

    // The payment settles: carried out by it, for the amount that was charged.
    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(World::$payments[$paymentId]['value'])->toBe($value);
});

it('still creates a payment on a later night when the first night failed before any payment existed', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();

    $this->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    World::$failPayments = true;
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    // No payment, so nothing for the stored change to own and nothing waiting.
    expect(retryAfterPaymentPaymentsCreated())->toBe(1)
        ->and(World::$payments)->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['charge_payment_id'] ?? null)->toBeNull()
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([]);

    retryAfterPaymentRunUntilDropped($billable);

    // Dropped with nothing to withdraw: no lookup at Mollie, no marker.
    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull()
        // Mollie was asked whether the refused request had arrived after all — it had not.
        ->and($billable->getBillingSubscriptionMeta()[\GraystackIT\MollieBilling\Services\Billing\InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull()
        ->and(array_filter(World::$sent, fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\GetPaymentRequest))->toBe([]);
});

it('returns a payment that had already settled at Mollie when the change was dropped, instead of applying it', function (): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [, $gross, $value] = retryAfterPaymentExpectedCharge();
    // Paid at Mollie; our webhook has not been through yet.
    World::$paymentStatus[$paymentId] = 'paid';

    retryAfterPaymentRunUntilDropped($billable);

    expect(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)->toBe('already_settled')
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeTrue();

    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe([$value])
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail()->amount_gross)->toBe($gross);

    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
});

it('puts seats back, locally and at Mollie, when a seat change is dropped after its payment was created', function (): void {
    // Three seats from the period end; pulled forward, the seat is charged pro rata. The
    // seat is written and Mollie patched before the charge clears — then a listener throws.
    $billable = World::subscription('none', 'pro', 2, []);
    app(UpdateSubscription::class)->update($billable, ['seats' => 3, 'apply_at' => 'end_of_period']);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->not->toBeNull();

    $throw = true;
    Event::listen(\GraystackIT\MollieBilling\Events\SeatsChanged::class, function () use (&$throw): void {
        if ($throw) {
            $throw = false;

            throw new RuntimeException('The app listener is down');
        }
    });

    $this->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    $paymentId = (string) ($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? '');
    // One seat at 5.00 for 12 of 31 days, plus 20 % VAT.
    $net = (int) round(500 * 12 / 31);
    $gross = $net + (int) round($net * 0.20);

    expect($paymentId)->not->toBe('')
        ->and(World::$payments[$paymentId]['value'])->toBe(number_format($gross / 100, 2, '.', ''))
        ->and($billable->getBillingSeatCount())->toBe(3)
        // Pro 50.00 + one seat 5.00, gross.
        ->and(World::$heldValue)->toBe('66.00')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['charge_payment_id'] ?? null)->toBe($paymentId);

    retryAfterPaymentRunUntilDropped($billable);

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and(World::$heldValue)->toBe('60.00')
        ->and(retryAfterPaymentPaymentsCreated())->toBe(1);

    // The payment arrives anyway: returned, and the seat stays off.
    World::deliver($billable, $paymentId, 'paid', World::$payments[$paymentId]['value'], ['type' => 'prorata_charge']);

    expect($billable->refresh()->getBillingSeatCount())->toBe(2)
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe([World::$payments[$paymentId]['value']]);
});

it('withdraws the change even when Mollie cannot be asked about its payment, and cancels the payment on the daily pass', function (bool $cancelableLater): void {
    $billable = retryAfterPaymentScheduledUpgrade();
    $paymentId = retryAfterPaymentFirstNightFails($billable);
    [, $gross, $value] = retryAfterPaymentExpectedCharge();

    // Mollie cannot be reached when the change is given up.
    World::$failLookups = true;
    retryAfterPaymentRunUntilDropped($billable);
    World::$failLookups = false;

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        // Withdrawn all the same: nothing is left that the payment could carry out …
        ->and(retryAfterPaymentPendingKeys($billable))->toBe([])
        // … and the payment is remembered, for the screens and for the next pass.
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)->toBe('lookup_failed')
        ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeTrue()
        ->and(array_filter(World::$sent, fn (object $r): bool => $r instanceof CancelPaymentRequest))->toBe([]);

    // The daily pass asks again.
    World::$cancelable[$paymentId] = $cancelableLater;
    $this->travelTo(Carbon::parse('2026-01-23 04:00:00', 'UTC'));
    (new \GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob)->handle();
    $billable->refresh();

    $cancels = array_values(array_filter(World::$sent, fn (object $r): bool => $r instanceof CancelPaymentRequest));

    if ($cancelableLater) {
        expect($cancels)->toHaveCount(1)
            ->and(World::$paymentStatus[$paymentId] ?? null)->toBe('canceled')
            ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment'] ?? null)->toBeNull()
            ->and(ScheduleSubscriptionChange::droppedNotice($billable)['payment_on_its_way'] ?? null)->toBeFalse();

        // Mollie reports the cancellation: nothing to do, nobody told.
        Event::fake([PlanChangeFailed::class]);
        World::deliver($billable, $paymentId, 'canceled', $value, ['type' => 'prorata_charge']);
        Event::assertNotDispatched(PlanChangeFailed::class);
        expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
            ->and(World::$refunds)->toBe([]);

        return;
    }

    // Past stopping: kept, and no longer asked about every day.
    expect($cancels)->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['reason'] ?? null)->toBe('not_cancelable');

    // It settles: returned in full, once; the plan stays.
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);
    World::deliver($billable, $paymentId, 'paid', $value, ['type' => 'prorata_charge']);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$paymentId] ?? []))->toBe([$value])
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail()->amount_gross)->toBe($gross);

    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
})->with([
    'cancelable by the next pass' => [true],
    'under way by then' => [false],
]);
