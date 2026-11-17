<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UndercollectionCharge;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * What the package resolves on its own must still be visible: the balance it charged
 * after a renewal that was too low, and the scheduled change it is retrying or has
 * dropped — to the customer where they look (dashboard, plan screen, invoice list) and
 * to the operator where they look (scheduled changes, the billable's subscription tab).
 */

beforeEach(function (): void {
    World::boot();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

function surfaceScreen(string $screen, TestBillable $billable, array $params = []): Testable
{
    MollieBilling::resolveBillableUsing(fn () => TestBillable::query()->find($billable->getKey()) ?? $billable);
    MollieBilling::authUsing(fn (): bool => true);

    return Livewire::test('mollie-billing::'.$screen, $params);
}

/**
 * A seat added on 31 Jan, after Mollie had created the February debit at the old amount:
 * the renewal is 5.00 net short, and the balance (6.00 gross) is charged on its own.
 *
 * @return array{0: TestBillable, 1: string, 2: string, 3: array<string, mixed>} billable, payment id, value, metadata
 */
function surfaceBalanceCharge(): array
{
    $billable = World::subscription('none', 'pro', 2, [], at: '2026-01-31 12:00:00');
    $value = (string) World::$heldValue;
    $metadata = World::$heldMetadata;

    World::change($billable, ['seats' => 3]);

    test()->travelTo(Carbon::parse('2026-02-01 09:01:00', 'UTC'));
    World::deliver($billable, 'tr_cm_early', 'paid', $value, $metadata, '2026-02-01T09:00:00+00:00', recurring: true);

    foreach (World::$payments as $id => $payment) {
        if (isset($payment['metadata'][UndercollectionCharge::METADATA_KEY])) {
            return [$billable->refresh(), (string) $id, (string) $payment['value'], (array) $payment['metadata']];
        }
    }

    throw new RuntimeException('no balance charge was created');
}

function surfaceScheduledUpgrade(): TestBillable
{
    $billable = World::subscription('none', 'pro', 2, []);
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    return $billable->refresh();
}

function surfaceFailedNights(TestBillable $billable, int $nights): TestBillable
{
    World::$failPayments = true;

    for ($night = 0; $night < $nights; $night++) {
        test()->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC')->addDays($night));
        app(ScheduleSubscriptionChange::class)->apply($billable->refresh());
    }

    World::$failPayments = false;

    return $billable->refresh();
}

// ── The balance charged after a renewal that was too low ──────────────────────

it('reads a balance charge as pending, paid, failed or not possible', function (): void {
    [$billable, $paymentId, $value, $metadata] = surfaceBalanceCharge();

    $case = UndercollectionCharge::cases($billable)[0];
    expect($case['status'])->toBe(UndercollectionCharge::STATUS_PENDING)
        ->and($case['amount_gross'])->toBe(600)
        ->and($case['reason'])->toBe('renewal_created_before_change')
        ->and(UndercollectionCharge::openCases($billable))->toHaveCount(1);

    World::deliver($billable, $paymentId, 'paid', $value, $metadata);
    $case = UndercollectionCharge::cases($billable->refresh())[0];
    expect($case['status'])->toBe(UndercollectionCharge::STATUS_PAID)
        ->and($case['charged_invoice_id'])->not->toBeNull()
        ->and(UndercollectionCharge::openCases($billable))->toBe([]);
});

it('shows the customer an open balance charge on the dashboard with amount and reason, and takes it away once it is paid', function (): void {
    [$billable, $paymentId, $value, $metadata] = surfaceBalanceCharge();

    surfaceScreen('dashboard', $billable)
        ->assertSee(__('billing::portal.balance_charge.pending', ['amount' => BillingMoney::format(600)]))
        ->assertSee(__('billing::notifications.undercollection_charged.reasons.renewal_created_before_change'));

    World::deliver($billable, $paymentId, 'paid', $value, $metadata);

    surfaceScreen('dashboard', $billable->refresh())
        ->assertDontSee(__('billing::portal.balance_charge.pending', ['amount' => BillingMoney::format(600)]));
});

it('tells the customer on the dashboard when the balance charge was declined', function (): void {
    [$billable, $paymentId, $value, $metadata] = surfaceBalanceCharge();

    World::deliver($billable, $paymentId, 'failed', $value, $metadata);
    $billable->refresh();

    expect(UndercollectionCharge::cases($billable)[0]['status'])->toBe(UndercollectionCharge::STATUS_FAILED)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    surfaceScreen('dashboard', $billable)
        ->assertSee(__('billing::portal.balance_charge.failed', ['amount' => BillingMoney::format(600)]));
});

it('lists the paid balance charge as an invoice of its own, named and with its reason', function (): void {
    [$billable, $paymentId, $value, $metadata] = surfaceBalanceCharge();
    World::deliver($billable, $paymentId, 'paid', $value, $metadata);

    $invoice = BillingInvoice::query()->where('mollie_payment_id', $paymentId)->firstOrFail();
    $renewal = BillingInvoice::query()->where('mollie_payment_id', 'tr_cm_early')->firstOrFail();

    surfaceScreen('invoices', $billable->refresh())
        ->assertSee(__('billing::portal.balance_charge.invoice_label'))
        ->assertSee(__('billing::portal.undercollection_line.renewal_created_before_change', ['invoice' => (string) $renewal->serial_number]));

    expect(UndercollectionCharge::isBalanceInvoice($invoice))->toBeTrue()
        ->and(UndercollectionCharge::isBalanceInvoice($renewal))->toBeFalse();
});

it('shows the operator every balance charge of a billable with its status, and why one was not possible', function (): void {
    [$billable, $paymentId, $value, $metadata] = surfaceBalanceCharge();

    $tab = fn (): Testable => Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()]);

    $tab()->assertSee(__('billing::admin.balance_charges.heading'))
        ->assertSee(__('billing::admin.balance_charges.status.pending'))
        ->assertSee($paymentId);

    World::deliver($billable, $paymentId, 'paid', $value, $metadata);
    $tab()->assertSee(__('billing::admin.balance_charges.status.paid'));

    // A billable without a mandate: nothing can be charged, and the tab says so.
    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta[UndercollectionCharge::META_KEY]['renewal_created_before_change:999'] = [
        'outcome' => 'reported', 'obstacle' => 'no_mandate', 'shortfall_net' => 500,
        'claimed_at' => now()->toIso8601String(),
    ];
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $tab()->assertSee(__('billing::admin.balance_charges.status.not_possible'))
        ->assertSee(__('billing::admin.balance_charges.obstacle.no_mandate'));
});

it('shows the operator nothing about balance charges where there are none', function (): void {
    $billable = World::subscription('none', 'pro', 2);

    Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()])
        ->assertDontSee(__('billing::admin.balance_charges.heading'));
});

// ── A scheduled change that could not be applied ───────────────────────────────

it('says which attempt comes next while a scheduled change is being retried', function (int $nights) {
    $billable = surfaceFailedNights(surfaceScheduledUpgrade(), $nights);

    expect(ScheduleSubscriptionChange::retryState($billable))->toMatchArray(['attempts' => $nights, 'next_attempt' => $nights + 1, 'max' => 3]);

    $text = __('billing::portal.scheduled_change_retry', ['attempt' => $nights + 1, 'max' => 3]);

    surfaceScreen('dashboard', $billable)->assertSee($text);
    surfaceScreen('plan-change', $billable)->assertSee($text);

    Livewire::test('mollie-billing::admin.scheduled-changes.index')
        ->assertSee(__('billing::admin.scheduled_changes.attempts', ['attempts' => $nights, 'max' => 3]))
        ->assertSee('Mollie refused the payment');
})->with([1, 2]);

it('says nothing about attempts while a scheduled change has not failed', function (): void {
    $billable = surfaceScheduledUpgrade();

    expect(ScheduleSubscriptionChange::retryState($billable))->toBeNull();

    surfaceScreen('dashboard', $billable)->assertDontSee(__('billing::portal.scheduled_change_retry', ['attempt' => 2, 'max' => 3]));
});

it('tells the customer why a scheduled change was given up after the last attempt', function (): void {
    $billable = surfaceFailedNights(surfaceScheduledUpgrade(), 3);

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(ScheduleSubscriptionChange::droppedNotice($billable))->toMatchArray(['plan_code' => 'enterprise', 'reason' => 'attempts_exhausted', 'attempts' => 3]);

    $text = __('billing::portal.scheduled_change_dropped', ['plan' => 'Enterprise']);
    $reason = __('billing::notifications.scheduled_change_not_applied.reasons.attempts_exhausted');

    surfaceScreen('dashboard', $billable)->assertSee($text)->assertSee($reason);
    surfaceScreen('plan-change', $billable)->assertSee($text)->assertSee($reason);
});

it('tells the customer why a scheduled change the rules refuse was dropped at once', function (): void {
    // Two more seats scheduled, and the subscription past due by the time they are due.
    $billable = World::subscription('none', 'pro', 2, [], used: 2);
    app(UpdateSubscription::class)->update($billable, ['seats' => 4, 'apply_at' => 'end_of_period']);
    $billable = World::failRenewal($billable->refresh());

    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and(ScheduleSubscriptionChange::droppedNotice($billable))->toMatchArray(['reason' => 'payment_outstanding', 'attempts' => 0]);

    // Seats only: no plan to name.
    surfaceScreen('plan-change', $billable)
        ->assertSee(__('billing::portal.scheduled_change_dropped_generic'))
        ->assertSee(__('billing::notifications.scheduled_change_not_applied.reasons.payment_outstanding'));
    surfaceScreen('dashboard', $billable)
        ->assertSee(__('billing::portal.scheduled_change_dropped_generic'));
});

it('stops telling the customer once a new change is scheduled or two weeks have passed', function (): void {
    $billable = surfaceFailedNights(surfaceScheduledUpgrade(), 3);

    $this->travelTo(now()->addDays(15));
    expect(ScheduleSubscriptionChange::droppedNotice($billable->refresh()))->toBeNull();

    $this->travelBack();
    $billable = surfaceFailedNights(surfaceScheduledUpgrade(), 3);
    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    expect(ScheduleSubscriptionChange::droppedNotice($billable->refresh()))->toBeNull();
});
