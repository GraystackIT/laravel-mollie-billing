<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;

/**
 * The limits recorded after the first audit round, each with a test: fixed where the fix
 * is a correction, pinned where closing it would need a new money flow.
 */
beforeEach(function (): void {
    World::boot();
    config()->set('mollie-billing.billable_model', \GraystackIT\MollieBilling\Testing\TestBillable::class);
});

it('puts seats, addons and Mollie back when the sweep finds the prorata charge failed and no webhook came', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);
    $held = World::$heldValue;

    // A seat and an addon, paid by a debit that is still travelling: the local state and
    // Mollie's amount have both moved already.
    $report = World::change($billable, ['seats' => 4, 'addons' => ['storage']], settle: null);
    $paymentId = (string) $billable->refresh()->getBillingSubscriptionMeta()['pending_prorata_change']['charge_payment_id'];

    expect($billable->getBillingSeatCount())->toBe(4)
        ->and($billable->getActiveBillingAddonCodes())->toBe(['storage'])
        ->and(World::$heldValue)->not->toBe($held)
        ->and($report['charged'])->not->toBeNull();

    // Two days later Mollie reports it failed — and the webhook for that never arrived.
    World::$paymentStatus[$paymentId] = 'failed';
    $this->travelTo(Carbon::parse('2026-01-13 13:00:00', 'UTC'));

    (new CleanupStalePendingProrataChangeJob)->handle();

    $billable->refresh();

    // Back on what was paid for, in both places.
    expect($billable->getBillingSubscriptionMeta()['pending_prorata_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['plan_change_failed_at'] ?? null)->not->toBeNull()
        ->and($billable->getBillingSeatCount())->toBe(2)
        ->and($billable->getActiveBillingAddonCodes())->toBe([])
        ->and(World::$heldValue)->toBe($held);

    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(5000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
});

// ── A scheduled change Mollie was not told about ────────────────────────────

it('holds a scheduled upgrade whose announcement failed until its renewal is in, then charges it for the new period', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);

    // Mollie is unreachable when the change is scheduled.
    World::$failPatches = true;
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)->update($billable, [
        'plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);
    World::$failPatches = false;

    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['mollie_prepatched'] ?? null)->toBeNull()
        ->and(World::$heldValue)->toBe('60.00');

    // 1 Feb 10:00 — the dispatcher's turn, but the (SEPA) renewal has not settled. The
    // change used to be applied here at a factor of 0: Enterprise for a month at Pro's
    // price.
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->not->toBeNull();

    // 3 Feb: the renewal settles, at Pro — and is a Pro renewal.
    $renewal = World::renew($billable, '2026-02-03T07:55:00+00:00');
    expect((int) $renewal->amount_net)->toBe(5000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment')
        ->and($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro');

    // The next dispatcher run applies the change in a period that has been paid for:
    // 28 of 28 days left on 3 Feb → the whole difference, 200.00 − 50.00.
    $this->travelTo(Carbon::parse('2026-02-03 10:00:00', 'UTC'));
    $before = (int) \GraystackIT\MollieBilling\Models\BillingInvoice::query()->max('id');
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable);
    World::settle($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(World::sum(World::bookedSince($billable, $before)))->toBe(15000)
        ->and(World::$heldValue)->toBe('240.00');

    World::assertNothingOverRefunded($billable);
});

it('holds a scheduled interval change until the old interval\'s renewal is in, then switches with one credit and one charge', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);

    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro', 'interval' => 'yearly', 'apply_at' => 'end_of_period',
    ]);

    $this->travelTo(Carbon::parse('2026-02-01 10:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable);

    // Not while the monthly renewal is still on its way — a year charged on top of it
    // was the double payment this used to produce.
    expect(World::$payments)->toBe([])
        ->and($billable->refresh()->getBillingSubscriptionInterval())->toBe('monthly');

    World::renew($billable, '2026-02-03T07:55:00+00:00');

    $this->travelTo(Carbon::parse('2026-02-03 10:00:00', 'UTC'));
    $before = (int) \GraystackIT\MollieBilling\Models\BillingInvoice::query()->max('id');
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable);
    World::settle($billable);

    // The year in full, the month that was just paid credited in full (28/28).
    expect($billable->refresh()->getBillingSubscriptionInterval())->toBe('yearly')
        ->and(World::bookedSince($billable, $before))->toBe(World::normalise([
            ['plan', 'pro', 50000],
            ['plan', 'pro', -5000],
        ]));

    World::assertNothingOverRefunded($billable);
});

it('applies a due change anyway when no renewal arrives within the waiting time', function (): void {
    $billable = World::subscription('none', 'pro', 2, []);

    World::$failPatches = true;
    app(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period',
    ]);
    World::$failPatches = false;

    $this->travelTo(Carbon::parse('2026-02-09 10:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange::class)->apply($billable);

    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('starter')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(World::$heldValue)->toBe('24.00');
});

// ── Collected too little: recognised, recorded, reported — never charged ────

/**
 * The one RenewalUndercollected the world has seen, as [reason, expected, collected, shortfall].
 *
 * @return list<array{0: string, 1: int, 2: int, 3: int}>
 */
function changeMatrixUndercollections(): array
{
    return \GraystackIT\MollieBilling\Support\BillingAuditEntry::model()::query()
        ->where('event', 'renewal_undercollected')
        ->orderBy('id')
        ->get()
        ->map(fn ($row): array => [
            (string) $row->properties['replace']['reason'],
            (int) $row->properties['replace']['expected_cents'],
            (int) $row->properties['replace']['actual_cents'],
            (int) $row->properties['replace']['amount_cents'],
        ])
        ->all();
}

it('reports a plan change whose SEPA charge spanned a renewal, with the amount, and charges nothing', function (): void {
    // 30 Jan: Pro → Enterprise, 2 of 31 days left: 200.00 × 2/31 = 12.90 charged,
    // 50.00 × 2/31 = 3.23 to be credited. The debit is pending.
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-30 12:00:00');
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [$billable]);
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\RenewalUndercollected::class]);

    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);

    // 1 Feb: the renewal. Locally and at Mollie this is still Pro — the two agree — so
    // February is collected and booked as Pro: 50.00. Nothing to report yet.
    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(5000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->not->toContain('adjustment');
    \Illuminate\Support\Facades\Event::assertNotDispatched(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class);

    // 3 Feb: the prorata debit clears; the plan switches for the rest of February —
    // Enterprise for 26 of 28 days on a Pro payment: 200.00 × 26/28 − 50.00 × 26/28 = 185.71 − 46.43 = 139.28 short (each line rounded on its own).
    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    $paymentsBefore = count(World::$payments);
    World::settle($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and(World::$heldValue)->toBe('240.00');

    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class, 1);
    \Illuminate\Support\Facades\Event::assertDispatched(
        \GraystackIT\MollieBilling\Events\RenewalUndercollected::class,
        fn ($e): bool => $e->reason === 'plan_change_spanned_renewal'
            && $e->invoice?->is($renewal)
            && $e->collectedNet === 5000
            && $e->shortfallNet === 13928
            && $e->expectedNet === 18928,
    );

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $billable,
        \GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification::class,
        fn ($n): bool => ($n->context['shortfall_net'] ?? null) === 13928
            && ($n->context['reason'] ?? null) === 'plan_change_spanned_renewal'
            && ($n->context['invoice_id'] ?? null) === $renewal->id,
    );

    // Reported, not acted on: no further payment, no refund, the renewal as booked.
    expect(count(World::$payments))->toBe($paymentsBefore)
        ->and(World::$refunds[(string) $renewal->mollie_payment_id] ?? [])->toBe([])
        ->and((int) $renewal->fresh()->amount_net)->toBe(5000)
        ->and((int) $renewal->fresh()->refunded_net)->toBe(0)
        ->and($billable->getBillingSubscriptionStatus())->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active);

    // A re-delivery of the same payment reports nothing a second time.
    World::deliver($billable, array_key_last(World::$payments), 'paid', World::$payments[array_key_last(World::$payments)]['value'], ['type' => 'prorata_charge']);
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class, 1);
});

it('writes the undercollection of a spanned plan change to the audit trail', function (): void {
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-30 12:00:00');

    World::change($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    World::renew($billable);
    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    expect(changeMatrixUndercollections())->toBe([['plan_change_spanned_renewal', 18928, 5000, 13928]]);
});

it('gives the difference back when a downgrade\'s SEPA charge spanned a renewal', function (): void {
    // The mirror image: Enterprise → Pro on 30 Jan, pending; February renews as
    // Enterprise (200.00); on 3 Feb the plan becomes Pro for 26 of 28 days.
    $billable = World::subscription('none', 'enterprise', 2, [], at: '2026-01-30 12:00:00');
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\RenewalUndercollected::class]);

    World::change($billable, ['plan_code' => 'pro', 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    $renewal = World::renew($billable);
    expect((int) $renewal->amount_net)->toBe(20000);

    $this->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    // 185.71 − 46.43 = 139.28 back against the renewal — returning money needs nobody's
    // decision.
    expect($billable->refresh()->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and((int) $renewal->fresh()->refunded_net)->toBe(13928);

    \Illuminate\Support\Facades\Event::assertNotDispatched(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class);
    World::assertNothingOverRefunded($billable);
});

it('reports a seat added after Mollie created the next debit, with the amount, and charges nothing', function (): void {
    // 31 Jan: Mollie has already created the February debit at 60.00 (Pro, two seats).
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    \GraystackIT\MollieBilling\MollieBilling::notifyAdminUsing(fn (): array => [$billable]);
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\RenewalUndercollected::class]);

    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;

    World::change($billable, ['seats' => 3]);
    expect(World::$heldValue)->toBe('66.00');

    // The debit that was already on its way settles at what it was created with.
    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    $paymentsBefore = count(World::$payments);
    World::deliver($billable, 'tr_cm_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    $renewal = \GraystackIT\MollieBilling\Models\BillingInvoice::query()->where('mollie_payment_id', 'tr_cm_early')->firstOrFail();

    // Three seats for February, two paid — 5.00 net short, once. Booked to what was
    // paid and left that way.
    expect((int) $renewal->amount_net)->toBe(5000)
        ->and(array_column((array) $renewal->line_items, 'kind'))->toContain('adjustment')
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3)
        ->and(World::$refunds)->toBe([])
        ->and(count(World::$payments))->toBe($paymentsBefore);

    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class, 1);
    \Illuminate\Support\Facades\Event::assertDispatched(
        \GraystackIT\MollieBilling\Events\RenewalUndercollected::class,
        fn ($e): bool => $e->reason === 'renewal_created_before_change'
            && $e->invoice?->is($renewal)
            && $e->expectedNet === 5500
            && $e->collectedNet === 5000
            && $e->shortfallNet === 500,
    );

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $billable,
        \GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification::class,
        fn ($n): bool => ($n->context['shortfall_net'] ?? null) === 500
            && ($n->context['reason'] ?? null) === 'renewal_created_before_change',
    );

    // The same delivery again: the invoice exists, nothing is reported twice.
    World::deliver($billable, 'tr_cm_early', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class, 1);

    // From the next period on it is right, and silent.
    $next = World::renew($billable);
    expect((int) $next->amount_net)->toBe(5500)
        ->and(array_column((array) $next->line_items, 'kind'))->not->toContain('adjustment');
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class, 1);
});

it('writes the undercollection of an early-created renewal to the audit trail', function (): void {
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;

    World::change($billable, ['seats' => 3]);
    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_cm_early_audit', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    expect(changeMatrixUndercollections())->toBe([['renewal_created_before_change', 5500, 5000, 500]]);
});

it('does not report a renewal that is lower because of a discount', function (string $discount) {
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\RenewalUndercollected::class]);

    World::change($billable, ['seats' => 4]);
    World::renew($billable);
    World::renew($billable);

    \Illuminate\Support\Facades\Event::assertNotDispatched(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class);
})->with(['50 % coupon' => 'pct50', 'fixed coupon' => 'fixed', 'waiver for N periods' => 'waiver-periods']);

it('does not report a shortfall when the payment was created for the configuration the billable is on', function (): void {
    // A PATCH that never landed: Mollie charges the old amount, but the payment's own
    // metadata does not say it was priced for another configuration. That is a
    // `PaymentAmountMismatch`, as before — not this.
    $billable = World::subscription('none', 'pro', 3, []);
    \Illuminate\Support\Facades\Event::fake([\GraystackIT\MollieBilling\Events\RenewalUndercollected::class]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_cm_low', 'paid', '60.00', [
        'plan_code' => 'pro', 'interval' => 'monthly', 'extra_seats' => 1, 'addon_codes' => [],
    ], '2026-02-01T09:00:00+00:00', recurring: true);

    \Illuminate\Support\Facades\Event::assertNotDispatched(\GraystackIT\MollieBilling\Events\RenewalUndercollected::class);
});
