<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\RenewalUndercollected;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\SubscriptionPaymentFailedNotification;
use GraystackIT\MollieBilling\Notifications\UndercollectionChargedNotification;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use GraystackIT\MollieBilling\Support\BillingMoney;
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

// ── Collected too little: the balance is charged automatically, once ────────


/**
 * Every RenewalUndercollected in the audit trail, as [reason, expected, collected, shortfall, charged].
 *
 * @return list<array{0: string, 1: int, 2: int, 3: int, 4: bool}>
 */
function changeMatrixUndercollections(): array
{
    return BillingAuditEntry::model()::query()
        ->where('event', 'renewal_undercollected')
        ->orderBy('id')
        ->get()
        ->map(fn ($row): array => [
            (string) $row->properties['replace']['reason'],
            (int) $row->properties['replace']['expected_cents'],
            (int) $row->properties['replace']['actual_cents'],
            (int) $row->properties['replace']['amount_cents'],
            (bool) $row->properties['replace']['charged'],
        ])
        ->all();
}

/** Operator and customer recipients, so a test can tell who was written to. */
function changeMatrixRecipients(): TestBillable
{
    $admin = TestBillable::create(['name' => 'Operator', 'email' => uniqid('operator-').'@x.test']);
    MollieBilling::notifyAdminUsing(fn (): array => [$admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    return $admin;
}

/**
 * A seat added on 31 Jan, after Mollie created the February debit at the old amount; that
 * debit then settles on 1 Feb. Returns the billable and the renewal invoice.
 *
 * @return array{0: TestBillable, 1: BillingInvoice}
 */
function changeMatrixSeatAfterDebit(string $discount = 'none', string $renewalId = 'tr_cm_early', array $addons = []): array
{
    $billable = World::subscription($discount, 'pro', 2, $addons, at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;

    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, $renewalId, 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    return [$billable->refresh(), BillingInvoice::query()->where('mollie_payment_id', $renewalId)->firstOrFail()];
}

/** The back-charge payment the world was asked to create, as [id, value, metadata] — or null. */
function changeMatrixBackCharge(): ?array
{
    foreach (World::$payments as $id => $payment) {
        if (isset($payment['metadata'][UndercollectionCharge::METADATA_KEY])) {
            return [$id, $payment['value'], $payment['metadata']];
        }
    }

    return null;
}

it('charges a seat that was added after Mollie created the next debit, as its own payment and invoice', function (): void {
    $admin = changeMatrixRecipients();
    Event::fake([RenewalUndercollected::class]);

    [$billable, $renewal] = changeMatrixSeatAfterDebit();

    // Three seats for February, two in the debit: the renewal is booked to what was
    // paid — and the missing 5.00 (+ 20 %) is collected as a payment of its own.
    $charge = changeMatrixBackCharge();

    expect((int) $renewal->amount_net)->toBe(5000)
        ->and($charge)->not->toBeNull()
        ->and($charge[1])->toBe('6.00')
        ->and($charge[2]['type'])->toBe('prorata_charge')
        ->and(World::$refunds)->toBe([]);

    Event::assertDispatchedTimes(RenewalUndercollected::class, 1);
    Event::assertDispatched(RenewalUndercollected::class, fn ($e): bool => $e->reason === 'renewal_created_before_change'
        && $e->invoice?->is($renewal)
        && $e->expectedNet === 5500 && $e->collectedNet === 5000 && $e->shortfallNet === 500
        && $e->chargePaymentId === $charge[0]);

    // The customer is told the amount and the reason; nobody else needs to be.
    Notification::assertSentTo($billable, UndercollectionChargedNotification::class, fn ($n): bool => $n->amountGross === 600 && $n->reason === 'renewal_created_before_change');
    Notification::assertNothingSentTo($admin);

    // It settles: its own invoice, one line for the seat, naming the renewal it completes.
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $charge[0])->firstOrFail();

    expect((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_gross)->toBe(600)
        ->and(array_column((array) $invoice->line_items, 'kind'))->toBe(['seats'])
        ->and($invoice->line_items[0]['undercollection'])->toBeTrue()
        ->and($invoice->line_items[0]['completes_invoice_id'])->toBe($renewal->id)
        // February is paid in full now: 50.00 + 5.00.
        ->and((int) $renewal->amount_net + (int) $invoice->amount_net)->toBe(5500)
        ->and($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    // From the next period on the renewal itself is right, and nothing more is charged.
    $next = World::renew($billable);
    expect((int) $next->amount_net)->toBe(5500)
        ->and(array_column((array) $next->line_items, 'kind'))->not->toContain('adjustment')
        ->and(array_filter(World::$payments, fn (array $p): bool => isset($p['metadata'][UndercollectionCharge::METADATA_KEY])))->toHaveCount(1);
});

it('charges the balance once, however often the renewal and the back-charge are delivered', function (): void {
    changeMatrixRecipients();
    [$billable, $renewal] = changeMatrixSeatAfterDebit();
    $charge = changeMatrixBackCharge();

    // The renewal again (the invoice guard), and the case claimed a second time directly.
    World::deliver($billable, 'tr_cm_early', 'paid', '60.00', ['plan_code' => 'pro', 'interval' => 'monthly', 'extra_seats' => 0, 'addon_codes' => []], '2026-02-01T09:00:00+00:00', recurring: true);
    expect(app(UndercollectionCharge::class)->settle($billable, $renewal, 5500, 5000, 'renewal_created_before_change'))->toBeFalse();

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    expect(array_filter(World::$payments, fn (array $p): bool => isset($p['metadata'][UndercollectionCharge::METADATA_KEY])))->toHaveCount(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $charge[0])->count())->toBe(1)
        ->and(changeMatrixUndercollections())->toBe([['renewal_created_before_change', 5500, 5000, 500, true]]);

    Notification::assertSentToTimes($billable, UndercollectionChargedNotification::class, 1);
});

it('charges the balance with the VAT that applies to the customer', function (string $country, ?string $vatNumber, string $value, int $vat) {
    World::$country = $country;
    World::$vatNumber = $vatNumber;
    changeMatrixRecipients();

    [$billable] = changeMatrixSeatAfterDebit();
    $charge = changeMatrixBackCharge();

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $charge[0])->firstOrFail();

    expect($charge[1])->toBe($value)
        ->and((int) $invoice->amount_net)->toBe(500)
        ->and((int) $invoice->amount_vat)->toBe($vat);
})->with([
    'AT, 20 %' => ['AT', null, '6.00', 100],
    'DE, 19 %' => ['DE', null, '5.95', 95],
    'DE business, reverse charge' => ['DE', 'DE123456789', '5.00', 0],
]);

it('puts the subscription into the ordinary dunning when the back-charge fails', function (): void {
    $admin = changeMatrixRecipients();
    [$billable] = changeMatrixSeatAfterDebit();
    $charge = changeMatrixBackCharge();

    World::deliver($billable, $charge[0], 'failed', $charge[1], $charge[2]);
    $billable->refresh();

    // Exactly what a failed renewal does — the same handler.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and($billable->getBillingSubscriptionMeta()['payment_failure']['payment_id'])->toBe($charge[0])
        ->and($billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->not->toBeNull()
        ->and(BillingInvoice::query()->where('mollie_payment_id', $charge[0])->exists())->toBeFalse();

    Notification::assertSentTo($billable, SubscriptionPaymentFailedNotification::class);
    Notification::assertNothingSentTo($admin);
});

it('tells an admin, and only then, when Mollie refuses to create the back-charge', function (): void {
    $admin = changeMatrixRecipients();
    Event::fake([RenewalUndercollected::class]);

    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    $createdAt = World::$heldValue;
    $createdFor = World::$heldMetadata;
    World::change($billable, ['seats' => 3]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::$failPayments = true;
    World::deliver($billable, 'tr_cm_refused', 'paid', (string) $createdAt, $createdFor, '2026-02-01T09:00:00+00:00', recurring: true);

    expect(changeMatrixBackCharge())->toBeNull();

    Event::assertDispatched(RenewalUndercollected::class, fn ($e): bool => $e->chargePaymentId === null && $e->shortfallNet === 500);
    Notification::assertSentTo($admin, AdminPlanChangeFailedNotification::class, fn ($n): bool => ($n->context['shortfall_net'] ?? null) === 500
        && ($n->context['obstacle'] ?? null) === 'payment_not_created');
    Notification::assertNotSentTo($billable, UndercollectionChargedNotification::class);
});

it('does not charge a subscription that is past due, cancelled or expired — it reports instead', function (SubscriptionStatus $status) {
    $admin = changeMatrixRecipients();
    $billable = World::subscription('none', 'pro', 3, []);
    $renewal = BillingInvoice::query()->where('billable_id', $billable->getKey())->latest('id')->firstOrFail();
    $billable->forceFill(['subscription_status' => $status])->save();

    expect(app(UndercollectionCharge::class)->settle($billable->refresh(), $renewal, 6000, 5500, 'renewal_created_before_change'))->toBeTrue()
        ->and(changeMatrixBackCharge())->toBeNull();

    Notification::assertSentTo($admin, AdminPlanChangeFailedNotification::class, fn ($n): bool => ($n->context['obstacle'] ?? null) === 'subscription_'.$status->value);
    Notification::assertNotSentTo($billable, UndercollectionChargedNotification::class);
})->with([SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired]);

it('charges nothing within the rounding slack', function (): void {
    changeMatrixRecipients();
    $billable = World::subscription('none', 'pro', 3, []);
    $renewal = BillingInvoice::query()->where('billable_id', $billable->getKey())->latest('id')->firstOrFail();

    expect(app(UndercollectionCharge::class)->settle($billable, $renewal, 5501, 5500, 'renewal_created_before_change'))->toBeFalse()
        ->and(app(UndercollectionCharge::class)->settle($billable, $renewal, 5500, 5600, 'renewal_created_before_change'))->toBeFalse()
        ->and(World::$payments)->toBe([]);

    Notification::assertNotSentTo($billable, UndercollectionChargedNotification::class);
});

it('charges the seat in full under a coupon or waiver, which does not cover it', function (string $discount) {
    changeMatrixRecipients();
    // With a paid addon, so that there is a renewal at all under the waiver (a plan that
    // is free with nothing bought on top is not charged).
    [$billable, $renewal] = changeMatrixSeatAfterDebit($discount, addons: ['storage']);

    // The discount is locked to what it was granted on; the added seat is 5.00 either
    // way, and that is what the debit is short of.
    $charge = changeMatrixBackCharge();

    expect($charge)->not->toBeNull()
        ->and($charge[1])->toBe('6.00')
        ->and(changeMatrixUndercollections())->toHaveCount(1)
        ->and(changeMatrixUndercollections()[0][3])->toBe(500);
})->with(['50 % coupon' => 'pct50', 'fixed coupon' => 'fixed', 'waiver with a paid addon' => 'waiver-periods']);

it('does not charge for a renewal that is lower because of a discount', function (string $discount) {
    changeMatrixRecipients();
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    Event::fake([RenewalUndercollected::class]);

    World::change($billable, ['seats' => 4]);
    $paymentsAfterChange = count(World::$payments);
    World::renew($billable);
    World::renew($billable);

    Event::assertNotDispatched(RenewalUndercollected::class);
    expect(count(World::$payments))->toBe($paymentsAfterChange);
})->with(['50 % coupon' => 'pct50', 'fixed coupon' => 'fixed', 'waiver for N periods' => 'waiver-periods']);

it('does not charge when the payment was created for the configuration the billable is on', function (): void {
    // A PATCH that never landed: Mollie charges the old amount, but the payment's own
    // metadata does not say it was priced for another configuration. That is a
    // `PaymentAmountMismatch`, as before — not this.
    $billable = World::subscription('none', 'pro', 3, []);
    Event::fake([RenewalUndercollected::class]);

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_cm_low', 'paid', '60.00', [
        'plan_code' => 'pro', 'interval' => 'monthly', 'extra_seats' => 1, 'addon_codes' => [],
    ], '2026-02-01T09:00:00+00:00', recurring: true);

    Event::assertNotDispatched(RenewalUndercollected::class);
    expect(World::$payments)->toBe([]);
});

it('leaves a renewal that is short under a discount to the mismatch report when its metadata is the billable\'s own', function (string $discount) {
    // The amount is lower than the discounted price, and the payment says it was priced
    // for exactly the configuration the billable is on. Nothing was added after the debit
    // was created — so there is no balance of a later change to collect.
    $billable = World::subscription($discount, 'pro', 3, ['storage']);
    Event::fake([RenewalUndercollected::class]);
    $payments = count(World::$payments);
    $short = BillingMoney::mollieAmount(World::heldGross() - 600, 'EUR');

    $this->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_cm_short', 'paid', $short, World::$heldMetadata, '2026-02-01T09:00:00+00:00', recurring: true);

    Event::assertNotDispatched(RenewalUndercollected::class);
    expect(count(World::$payments))->toBe($payments)
        ->and($billable->refresh()->getBillingSubscriptionMeta()['undercollection_charges'] ?? [])->toBe([]);
})->with(['50 % coupon' => 'pct50', 'fixed coupon' => 'fixed', 'waiver for N periods' => 'waiver-periods']);

// ── A plan change whose SEPA charge spanned a renewal ───────────────────────

/**
 * Pro → `$target` on 30 Jan (pending), the renewal on 1 Feb booked for the old plan, the
 * prorata debit settling on 3 Feb. Returns the billable and that renewal.
 *
 * @return array{0: TestBillable, 1: BillingInvoice}
 */
function changeMatrixSpannedRenewal(string $from, string $target, string $discount = 'none'): array
{
    $billable = World::subscription($discount, $from, 2, [], at: '2026-01-30 12:00:00');

    World::change($billable, ['plan_code' => $target, 'interval' => 'monthly', 'apply_at' => 'immediate'], settle: null);
    $renewal = World::renew($billable);

    test()->travelTo(Carbon::parse('2026-02-03 12:00:00', 'UTC'));
    World::settle($billable);

    return [$billable->refresh(), $renewal];
}

it('charges the rest of the period when an upgrade\'s SEPA payment spanned a renewal', function (): void {
    $admin = changeMatrixRecipients();
    Event::fake([RenewalUndercollected::class]);

    [$billable, $renewal] = changeMatrixSpannedRenewal('pro', 'enterprise');
    $charge = changeMatrixBackCharge();

    // February was collected as Pro (50.00); from 3 Feb it is Enterprise for 26 of 28
    // days: 185.71 − 46.43 = 139.28 net, 167.14 gross — charged on its own.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and((int) $renewal->amount_net)->toBe(5000)
        ->and($charge)->not->toBeNull()
        ->and($charge[1])->toBe('167.14')
        ->and(World::$refunds[(string) $renewal->mollie_payment_id] ?? [])->toBe([]);

    Event::assertDispatchedTimes(RenewalUndercollected::class, 1);
    Event::assertDispatched(RenewalUndercollected::class, fn ($e): bool => $e->reason === 'plan_change_spanned_renewal'
        && $e->invoice?->is($renewal)
        && $e->collectedNet === 5000 && $e->shortfallNet === 13928 && $e->expectedNet === 18928
        && $e->chargePaymentId === $charge[0]);

    Notification::assertSentTo($billable, UndercollectionChargedNotification::class, fn ($n): bool => $n->amountGross === 16714 && $n->reason === 'plan_change_spanned_renewal');
    Notification::assertNothingSentTo($admin);

    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);
    // Re-delivery of the plan change's own payment and of the back-charge: nothing twice.
    World::deliver($billable, $charge[0], 'paid', $charge[1], $charge[2]);

    expect((int) BillingInvoice::query()->where('mollie_payment_id', $charge[0])->sole()->amount_net)->toBe(13928)
        ->and(array_filter(World::$payments, fn (array $p): bool => isset($p['metadata'][UndercollectionCharge::METADATA_KEY])))->toHaveCount(1);

    $next = World::renew($billable);
    expect((int) $next->amount_net)->toBe(20000)
        ->and(array_column((array) $next->line_items, 'kind'))->not->toContain('adjustment');
});

it('writes the automatic back-charge of a spanned plan change to the audit trail', function (): void {
    changeMatrixRecipients();
    changeMatrixSpannedRenewal('pro', 'enterprise');

    expect(changeMatrixUndercollections())->toBe([['plan_change_spanned_renewal', 18928, 5000, 13928, true]]);
});

it('charges the discounted balance when the spanned upgrade runs under a coupon', function (): void {
    changeMatrixRecipients();
    [$billable, $renewal] = changeMatrixSpannedRenewal('pro', 'enterprise', 'pct50');
    $charge = changeMatrixBackCharge();

    // Paid for February: 25.00 (50 % of Pro). Enterprise under the same coupon is
    // 200.00 − 25.00 = 175.00. The balance is (175.00 − 25.00) × 26/28, each line
    // rounded on its own: 185.71 − 23.21 (coupon) − 23.21 (paid share) = 139.29.
    expect((int) $renewal->amount_net)->toBe(2500)
        ->and($charge)->not->toBeNull()
        ->and(changeMatrixUndercollections()[0][3])->toBe(13929);
});

it('gives the difference back — and charges nothing — when a downgrade\'s SEPA payment spanned a renewal', function (): void {
    changeMatrixRecipients();
    Event::fake([RenewalUndercollected::class]);

    [$billable, $renewal] = changeMatrixSpannedRenewal('enterprise', 'pro');

    // The mirror image: February was collected as Enterprise. 185.71 − 46.43 = 139.28
    // back against the renewal. The two directions exclude each other.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and((int) $renewal->fresh()->refunded_net)->toBe(13928)
        ->and(changeMatrixBackCharge())->toBeNull();

    Event::assertNotDispatched(RenewalUndercollected::class);
    World::assertNothingOverRefunded($billable);
});
