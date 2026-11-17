<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\UnwantedPaymentRefunded;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\ScheduledChangeNotAppliedNotification;
use GraystackIT\MollieBilling\Notifications\UnwantedPaymentRefundedNotification;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/**
 * Mollie accepted the prorata payment, but we never learnt its id — the answer was lost
 * (a timeout), or the write that records it failed. Nothing points at the payment, so
 * the next attempt used to create a second one: two debits for one change.
 *
 * `InvoiceService::createCharge()` therefore notes the payment it is about to create
 * (`prorata_charge_in_creation`, with a token that also goes into the payment's metadata)
 * before it asks Mollie. Whoever comes next — the next attempt, or the payment's own
 * webhook — finds the payment by that token and takes it over:
 *
 *  - the same change again: the payment is adopted, no second one is created;
 *  - its webhook first: the change is carried out by it, once;
 *  - a different change: the lost payment is cancelled where Mollie allows it, otherwise
 *    returned in full when it arrives; the new change is charged and applied normally;
 *  - Mollie holds no such payment: the request never arrived, the next one is created;
 *  - Mollie cannot be asked: nothing is created. An unanswered question is not "none".
 */
beforeEach(function (): void {
    World::boot();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

/** Pro → Enterprise for the period end (1 Feb). */
function chargeInCreationScheduledUpgrade(): TestBillable
{
    $billable = World::subscription('none', 'pro', 2, []);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    return $billable->refresh();
}

/** Night one, 20 January: Mollie creates the payment, the answer never arrives. Returns its id. */
function chargeInCreationFirstNightTimesOut(TestBillable $billable): string
{
    test()->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    World::$acceptThenTimeout = true;
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    return (string) array_key_last(World::$payments);
}

function chargeInCreationNight(TestBillable $billable, string $day): void
{
    test()->travelTo(Carbon::parse($day.' 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();
}

function chargeInCreationRequests(): int
{
    return count(array_filter(World::$sent, fn (object $r): bool => $r instanceof CreatePaymentRequest));
}

/**
 * Enterprise for 12 of the 31 days of January (from the 20th), + 20 % Austrian VAT.
 *
 * @return array{0: int, 1: int, 2: string} net, gross, Mollie value
 */
function chargeInCreationTwelveDays(int $monthlyNet = 20000): array
{
    $net = (int) round($monthlyNet * 12 / 31);
    $gross = $net + (int) round($net * 0.20);

    return [$net, $gross, number_format($gross / 100, 2, '.', '')];
}

function chargeInCreationDeliver(TestBillable $billable, string $paymentId, string $status = 'paid'): void
{
    World::$paymentStatus[$paymentId] = $status;
    World::deliver($billable, $paymentId, $status, World::$payments[$paymentId]['value'], World::$payments[$paymentId]['metadata']);
    $billable->refresh();
}

it('notes the payment before asking Mollie, and carries its token in the payment metadata', function (): void {
    $billable = chargeInCreationScheduledUpgrade();
    $lost = chargeInCreationFirstNightTimesOut($billable);
    [, , $value] = chargeInCreationTwelveDays();

    $marker = $billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null;

    expect(World::$payments[$lost]['value'])->toBe($value)
        ->and($marker)->toBeArray()
        ->and($marker['token'] ?? null)->toBe(World::$payments[$lost]['metadata']['charge_token'] ?? 'missing')
        // Nothing points at the payment yet …
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        // … and the change is tried again.
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(1);
});

it('takes the lost payment over on the next attempt instead of creating a second one', function (): void {
    $billable = chargeInCreationScheduledUpgrade();
    $lost = chargeInCreationFirstNightTimesOut($billable);
    [$net, $gross] = chargeInCreationTwelveDays();

    chargeInCreationNight($billable, '2026-01-21');

    expect(chargeInCreationRequests())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe($lost)
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    // It settles: carried out once, at the amount that was charged.
    chargeInCreationDeliver($billable, $lost);
    chargeInCreationDeliver($billable, $lost);

    $invoices = BillingInvoice::query()->where('mollie_payment_id', $lost)->get();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($invoices)->toHaveCount(1)
        ->and((int) $invoices->first()->amount_net)->toBe($net)
        ->and((int) $invoices->first()->amount_gross)->toBe($gross)
        // Nothing of the payment goes back (the credit for Pro is a refund of its own).
        ->and(World::$refunds[$lost] ?? [])->toBe([])
        ->and(chargeInCreationRequests())->toBe(1);

    Notification::assertNotSentTo($billable, UnwantedPaymentRefundedNotification::class);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('carries the change out when the lost payment settles before the next attempt — once, and the next attempt adds nothing', function (): void {
    $billable = chargeInCreationScheduledUpgrade();
    $lost = chargeInCreationFirstNightTimesOut($billable);
    [$net, $gross] = chargeInCreationTwelveDays();

    // A card: settled within the hour, long before the next night.
    chargeInCreationDeliver($billable, $lost);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $lost)->firstOrFail()->amount_gross)->toBe($gross)
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and(World::$refunds[$lost] ?? [])->toBe([]);

    // Re-delivered.
    chargeInCreationDeliver($billable, $lost);
    expect(BillingInvoice::query()->where('mollie_payment_id', $lost)->count())->toBe(1);

    $invoices = BillingInvoice::query()->count();
    chargeInCreationNight($billable, '2026-01-21');

    expect(chargeInCreationRequests())->toBe(1)
        ->and(BillingInvoice::query()->count())->toBe($invoices)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(World::$refunds[$lost] ?? [])->toBe([]);

    Notification::assertNotSentTo($billable, UnwantedPaymentRefundedNotification::class);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('creates the payment normally when Mollie holds none — the first request never arrived', function (): void {
    $billable = chargeInCreationScheduledUpgrade();

    $this->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    World::$failPayments = true;
    app(ScheduleSubscriptionChange::class)->apply($billable);
    World::$failPayments = false;

    expect($billable->refresh()->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeArray()
        ->and(World::$payments)->toBe([]);

    chargeInCreationNight($billable, '2026-01-21');
    World::settle($billable);

    // 21–31 January = 11 of 31 days of Enterprise.
    $net = (int) round(20000 * 11 / 31);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(count(World::$payments))->toBe(1)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', array_key_first(World::$payments))->firstOrFail()->amount_net)->toBe($net)
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull();
});

it('creates nothing while Mollie cannot say whether the lost payment exists, and takes it over once it can', function (): void {
    $billable = chargeInCreationScheduledUpgrade();
    $lost = chargeInCreationFirstNightTimesOut($billable);

    World::$failPaymentLists = true;
    chargeInCreationNight($billable, '2026-01-21');

    expect(chargeInCreationRequests())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeArray()
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(2);

    World::$failPaymentLists = false;
    chargeInCreationNight($billable, '2026-01-22');

    expect(chargeInCreationRequests())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'] ?? null)->toBe($lost);
});

it('charges and applies a different change normally and returns the lost payment of the earlier one in full', function (): void {
    // The customer asks for Enterprise now; Mollie takes the payment, the answer is lost.
    $billable = World::subscription('none', 'pro', 2, []);
    $this->travelTo(Carbon::parse('2026-01-20 10:00:00', 'UTC'));
    World::$acceptThenTimeout = true;

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']))
        ->toThrow(RuntimeException::class);

    $lost = (string) array_key_last(World::$payments);
    [, $lostGross, $lostValue] = chargeInCreationTwelveDays();

    // They try again, this time with a third seat: a different change, a payment of its own.
    World::change($billable->refresh(), ['plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate'], settle: null);
    $second = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    // Enterprise and one seat (5.00) for 12 of 31 days — one line each, each rounded
    // and taxed on its own.
    $planNet = (int) round(20000 * 12 / 31);
    $seatNet = (int) round(500 * 12 / 31);
    $secondGross = $planNet + (int) round($planNet * 0.20) + $seatNet + (int) round($seatNet * 0.20);

    expect($second)->not->toBe($lost)
        ->and(World::$payments[$second]['value'])->toBe(number_format($secondGross / 100, 2, '.', ''));

    World::settle($billable);
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $second)->firstOrFail()->amount_gross)->toBe($secondGross);

    // The lost one arrives — twice: returned in full, once; nothing moves.
    Event::fake([UnwantedPaymentRefunded::class]);
    chargeInCreationDeliver($billable, $lost);
    chargeInCreationDeliver($billable, $lost);

    expect(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$lost] ?? []))->toBe([$lostValue])
        ->and(World::$refunds[$second] ?? [])->toBe([])
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $lost)->firstOrFail()->amount_gross)->toBe($lostGross);

    Event::assertDispatchedTimes(UnwantedPaymentRefunded::class, 1);
    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
});

it('cancels the lost payment of a different change where Mollie still allows it', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);
    $this->travelTo(Carbon::parse('2026-01-20 10:00:00', 'UTC'));
    World::$acceptThenTimeout = true;

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']))
        ->toThrow(RuntimeException::class);

    $lost = (string) array_key_last(World::$payments);
    World::$cancelable[$lost] = true;

    World::change($billable->refresh(), ['plan_code' => 'enterprise', 'interval' => 'monthly', 'seats' => 3, 'apply_at' => 'immediate']);

    $cancelled = array_values(array_filter(World::$sent, fn (object $r): bool => $r instanceof CancelPaymentRequest));

    expect($cancelled)->toHaveCount(1)
        ->and(World::$paymentStatus[$lost] ?? null)->toBe('canceled')
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull();
});

it('takes the same change over when the customer simply tries again', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);
    $this->travelTo(Carbon::parse('2026-01-20 10:00:00', 'UTC'));
    World::$acceptThenTimeout = true;
    $payload = ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'];

    expect(fn () => app(UpdateSubscription::class)->update($billable, $payload))->toThrow(RuntimeException::class);
    $lost = (string) array_key_last(World::$payments);

    World::change($billable->refresh(), $payload);

    expect(chargeInCreationRequests())->toBe(1)
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(BillingInvoice::query()->where('mollie_payment_id', $lost)->count())->toBe(1)
        ->and(World::$refunds[$lost] ?? [])->toBe([]);
});

it('does not carry a change out when its lost payment settles after the change was given up — it is returned', function (string $how): void {
    $billable = chargeInCreationScheduledUpgrade();
    $lost = chargeInCreationFirstNightTimesOut($billable);
    [, $gross, $value] = chargeInCreationTwelveDays();

    if ($how === 'dropped') {
        // Nights two and three cannot ask Mollie about it: nothing is created, the change
        // is given up — and the note goes with it.
        World::$failPaymentLists = true;
        chargeInCreationNight($billable, '2026-01-21');
        chargeInCreationNight($billable, '2026-01-22');
        World::$failPaymentLists = false;

        expect(ScheduleSubscriptionChange::droppedNotice($billable)['reason'] ?? null)->toBe('attempts_exhausted');
        Notification::assertSentToTimes($billable, ScheduledChangeNotAppliedNotification::class, 1);
    } else {
        // The customer withdraws the change; Mollie answers, the payment is found, and it
        // is past stopping.
        app(UpdateSubscription::class)->cancelScheduledChange($billable);
        $billable->refresh();

        expect($billable->getBillingSubscriptionMeta()['orphaned_prorata_payment']['payment_id'] ?? null)->toBe($lost);
        Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
    }

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and(chargeInCreationRequests())->toBe(1);

    chargeInCreationDeliver($billable, $lost);
    chargeInCreationDeliver($billable, $lost);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$lost] ?? []))->toBe([$value])
        ->and((int) BillingInvoice::query()->where('mollie_payment_id', $lost)->firstOrFail()->amount_gross)->toBe($gross);

    Notification::assertSentToTimes($billable, UnwantedPaymentRefundedNotification::class, 1);
})->with([
    'dropped after three nights, Mollie unreachable' => ['dropped'],
    'withdrawn by the customer, Mollie answers' => ['withdrawn'],
]);

it('drops a note nobody takes over within two days, cancels the payment where possible, and returns it otherwise', function (bool $cancelable): void {
    // The customer asked for Enterprise now, got an error and did not try again.
    $billable = World::subscription('none', 'pro', 2, []);
    $this->travelTo(Carbon::parse('2026-01-20 10:00:00', 'UTC'));
    World::$acceptThenTimeout = true;

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate']))
        ->toThrow(RuntimeException::class);

    $lost = (string) array_key_last(World::$payments);
    [, , $value] = chargeInCreationTwelveDays();
    World::$cancelable[$lost] = $cancelable;
    $job = new \GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;

    // A day later the note still stands: a nightly retry may still come for it.
    $this->travelTo(Carbon::parse('2026-01-21 10:00:00', 'UTC'));
    $job->handle();
    expect($billable->refresh()->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeArray();

    $this->travelTo(Carbon::parse('2026-01-22 10:01:00', 'UTC'));
    $job->handle();
    $billable->refresh();

    $cancels = array_values(array_filter(World::$sent, fn (object $r): bool => $r instanceof CancelPaymentRequest));

    expect($billable->getBillingSubscriptionMeta()[InvoiceService::CHARGE_IN_CREATION_KEY] ?? null)->toBeNull()
        ->and($cancels)->toHaveCount($cancelable ? 1 : 0);

    if ($cancelable) {
        return;
    }

    // It settles anyway: nothing claims it — returned in full, the plan stays.
    chargeInCreationDeliver($billable, $lost);

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(array_map(fn ($r): string => (string) $r->amount->value, World::$refunds[$lost] ?? []))->toBe([$value]);
})->with([
    'still cancelable' => [true],
    'under way' => [false],
]);
