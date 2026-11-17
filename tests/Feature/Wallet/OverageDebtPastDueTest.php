<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * An overage charge that failed for good puts the subscription into `past_due`.
 *
 * The maintainer's rule: a customer who owes money that most likely cannot be collected
 * any more books nothing new. The debt still stands and the next renewals still try to
 * collect it — so the renewal has to keep running in that state, the auto-cancel clock
 * (`past_due_max_days`) must not run for a subscription whose renewals are paid, and the
 * state ends when the debt is paid or finally written off.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

/** January ran 37 Tokens over (3.70 net); the charge on 9 February fails three times. */
function overageDebtPastDueRun(): UsageLongRunHarness
{
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
    config()->set('mollie-billing.past_due_max_days', 30);

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-24T12:00:00Z');
    $run->use('Tokens', 137);

    overageDebtPastDueNights($run, '2026-01-25', '2026-02-09');
    overageDebtPastDueFailOpen($run);

    return $run;
}

function overageDebtPastDueNights(UsageLongRunHarness $run, string $from, string $to): void
{
    for ($day = Carbon::parse($from); $day->lessThanOrEqualTo(Carbon::parse($to)); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
    }
}

function overageDebtPastDueFailOpen(UsageLongRunHarness $run): void
{
    for ($attempt = 0; $attempt < 6 && ($id = $run->openOveragePayment()) !== null; $attempt++) {
        $run->travelTo(Carbon::now('UTC')->addMinutes(10));
        $run->resolveOverage($id, 'failed');
    }
}

function overageDebtPastDueOpenNet(TestBillable $billable): int
{
    return (int) ($billable->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY]['amount_net'] ?? 0);
}

it('moves the subscription to past_due when the dunning cycle of an overage charge gives up, and keeps the debt', function (): void {
    $run = overageDebtPastDueRun();
    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebt($billable))->toBeTrue()
        ->and(overageDebtPastDueOpenNet($billable))->toBe(370)
        ->and($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
        // The renewal has not failed: its clock, which ends in a cancellation, is not running.
        ->and($meta)->not->toHaveKey('past_due_since')
        ->and($meta)->not->toHaveKey('payment_failure');
});

it('keeps renewing in past_due, collects plan and debt, and returns to active once the debt is paid', function (): void {
    $run = overageDebtPastDueRun();

    // 10 February: the plan is paid. The debt gave up a day ago and is not tried again yet.
    $run->travelTo('2026-02-10T09:02:00Z');
    $run->renew('2026-02-10T09:00:00Z');

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($run->balance('Tokens'))->toBe(100)
        ->and($run->invoiceCount('subscription'))->toBe(1)
        ->and($run->paymentRequests)->toBe(3);

    // More than `past_due_max_days` pass. Nobody cancels a subscription whose renewals are paid.
    overageDebtPastDueNights($run, '2026-02-11', '2026-03-09');
    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);

    // 10 March: plan and debt. Both are paid.
    $run->travelTo('2026-03-10T09:02:00Z');
    $run->renew('2026-03-10T09:00:00Z');

    expect($run->paymentRequests)->toBe(4)
        ->and($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);

    $run->settleOpenOverage();

    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebt($billable))->toBeFalse()
        ->and($meta)->not->toHaveKey(ChargeUsageOverageDirectly::PAST_DUE_KEY)
        ->and($meta)->not->toHaveKey('usage_overage')
        ->and($run->overageInvoicedNet())->toBe(370)
        ->and($run->invoiceCount('overage'))->toBe(1)
        ->and($run->invoiceCount('subscription'))->toBe(2);
});

it('lets the normal renewal dunning take over when the renewal fails as well, and does not count the debt twice', function (): void {
    $run = overageDebtPastDueRun();

    $run->travelTo('2026-02-10T09:02:00Z');
    $run->renew('2026-02-10T09:00:00Z', status: 'failed');

    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($meta['past_due_since'] ?? null)->not->toBeNull()
        ->and($meta['payment_failure']['payment_id'] ?? null)->not->toBeNull()
        // The debt is what it was: once, 3.70, and no further payment was asked for.
        ->and(overageDebtPastDueOpenNet($billable))->toBe(370)
        ->and(count((array) $meta[ChargeUsageOverageDirectly::META_KEY]['line_items']))->toBe(1)
        ->and($run->paymentRequests)->toBe(3)
        ->and($run->balance('Tokens'))->toBe(0);

    // With the renewal unpaid the ordinary clock runs: cancelled after `past_due_max_days`.
    overageDebtPastDueNights($run, '2026-02-11', '2026-03-14');

    expect($run->fresh()->subscription_status)->not->toBe(SubscriptionStatus::PastDue)
        ->and($run->fresh()->subscription_status)->not->toBe(SubscriptionStatus::Active)
        ->and($run->overageInvoicedNet())->toBe(0);
});

it('returns to active when the debt is written off and the renewals were paid', function (): void {
    $run = overageDebtPastDueRun();

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10'] as $renewal) {
        expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue, $renewal);

        overageDebtPastDueNights($run, Carbon::parse($renewal)->subDays(3)->toDateString(), Carbon::parse($renewal)->subDay()->toDateString());
        $run->travelTo($renewal.'T09:02:00Z');
        $run->renew($renewal.'T09:00:00Z');
        overageDebtPastDueFailOpen($run);
    }

    $billable = $run->fresh();

    // Three renewals tried (March, April, May): written off, one mail to operations.
    expect($run->paymentRequests)->toBe(12)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('usage_overage')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(ChargeUsageOverageDirectly::PAST_DUE_KEY)
        // Nothing is owed any more and every renewal was paid: the lock has no reason left.
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active);

    Notification::assertSentTimes(AdminOverageBillingFailedNotification::class, 1);
});

it('stays past_due after the write-off while a renewal is unpaid too', function (): void {
    $run = overageDebtPastDueRun();
    config()->set('mollie-billing.past_due_max_days', 0);

    foreach (['2026-02-10', '2026-03-10', '2026-04-10'] as $renewal) {
        overageDebtPastDueNights($run, Carbon::parse($renewal)->subDays(3)->toDateString(), Carbon::parse($renewal)->subDay()->toDateString());
        $run->travelTo($renewal.'T09:02:00Z');
        $run->renew($renewal.'T09:00:00Z');
        overageDebtPastDueFailOpen($run);
    }

    // May: the renewal itself fails. The debt's last cycle is run by the night before.
    $run->travelTo('2026-05-10T09:02:00Z');
    $run->renew('2026-05-10T09:00:00Z', status: 'failed');

    expect($run->fresh()->getBillingSubscriptionMeta()['past_due_since'] ?? null)->not->toBeNull();

    // Whatever writes the debt off, the unpaid renewal keeps the subscription past due.
    app(ChargeUsageOverageDirectly::class)->releasePastDueForDebt($run->fresh());

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

// ── Booking while the debt is open ──────────────────────────────────────────

/** Pro, three seats, storage, everything paid — and an overage debt of 3.70 that gave up. */
function overageDebtPastDueSubscriber(): TestBillable
{
    ChangeMatrixWorld::boot();
    $billable = ChangeMatrixWorld::subscription('none', 'pro', 3, ['storage'], used: 1);

    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::META_KEY] = [
        'payment_id' => 'tr_debt',
        'line_items' => [[
            'type' => 'Tokens', 'quantity' => 37, 'unit_price_net' => 10, 'total_net' => 370, 'settles_wallet' => false,
        ]],
        'amount_net' => 370,
        'amount_gross' => 440,
        'created_at' => now('UTC')->toIso8601String(),
    ];
    $meta['usage_overage_status'] = 'pending';
    $billable->forceFill(['subscription_meta' => $meta])->save();

    expect(app(ChargeUsageOverageDirectly::class)->deferFailedCycle($billable))->toBe('deferred');

    return $billable->refresh();
}

dataset('bookings refused under an overage debt', [
    'more seats' => [['seats' => 4]],
    'a paid addon' => [['addons' => ['storage', 'sso']]],
    'another plan' => [['plan_code' => 'enterprise']],
    'another interval' => [['interval' => 'yearly']],
]);

it('refuses every expansion while the overage debt is open, and changes nothing', function (array $payload): void {
    $billable = overageDebtPastDueSubscriber();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    expect(fn () => app(UpdateSubscription::class)->update($billable, $payload))
        ->toThrow(PastDueExpansionNotAllowedException::class);

    $errors = app(PreviewService::class)->previewUpdate($billable, $payload)['errors'];
    expect(array_column($errors, 'type'))->toContain('past_due_expansion');

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->toBe($meta)
        ->and($billable->getBillingSeatCount())->toBe(3)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(ChangeMatrixWorld::$payments)->toBe([]);
})->with('bookings refused under an overage debt');

it('refuses through the methods an application calls on the billable', function (): void {
    $billable = overageDebtPastDueSubscriber();

    expect(fn () => $billable->syncBillingSeats(4))->toThrow(PastDueExpansionNotAllowedException::class)
        ->and(fn () => $billable->enableBillingAddon('sso'))->toThrow(PastDueExpansionNotAllowedException::class)
        ->and(fn () => $billable->changeBillingPlan('enterprise', 'monthly'))->toThrow(PastDueExpansionNotAllowedException::class);
});

it('refuses a one-time order while the overage debt is open', function (): void {
    $billable = overageDebtPastDueSubscriber();
    config()->set('mollie-billing-plans.products.credits', ['name' => 'Credits', 'price_net' => 1000]);

    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'credits']))
        ->toThrow(PastDueExpansionNotAllowedException::class);

    expect(ChangeMatrixWorld::$payments)->toBe([]);
});

it('still lets the customer shrink, and lets them book again once the debt is settled', function (): void {
    $billable = overageDebtPastDueSubscriber();

    app(UpdateSubscription::class)->update($billable, ['seats' => 2]);
    expect($billable->refresh()->getBillingSeatCount())->toBe(2);

    app(ChargeUsageOverageDirectly::class)->releasePastDueForDebt($billable);

    expect($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    $errors = app(PreviewService::class)->previewUpdate($billable, ['seats' => 4])['errors'];
    expect(array_column($errors, 'type'))->not->toContain('past_due_expansion');
});

// ── The remaining ways to book, and the ways out of past due ────────────────

dataset('both kinds of past due', [
    'a renewal is unpaid' => [false],
    'an overage debt stands' => [true],
]);

it('refuses extras bought on top of a grant while past due', function (bool $overageDebt): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Grant', 'email' => uniqid('gr-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly,
        'subscription_ends_at' => now('UTC')->addMonth(),
        'subscription_meta' => $overageDebt
            ? [ChargeUsageOverageDirectly::PAST_DUE_KEY => now('UTC')->toIso8601String()]
            : ['past_due_since' => now('UTC')->toIso8601String()],
    ])->save();

    $upgrade = Mockery::mock(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class);
    $upgrade->shouldNotReceive('handleKeepingGrant');
    app()->instance(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class, $upgrade);

    expect(fn () => app(\GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase::class)->handle($billable->fresh(), ['sso'], 3))
        ->toThrow(PastDueExpansionNotAllowedException::class, __('billing::portal.error_past_due_booking'));
})->with('both kinds of past due');

it('says in both languages why nothing can be booked', function (): void {
    expect(__('billing::portal.error_past_due_booking', [], 'en'))->not->toContain('billing::')
        ->and(__('billing::portal.error_past_due_booking', [], 'de'))->not->toContain('billing::')
        ->and(__('billing::portal.error_past_due_booking', [], 'de'))->not->toBe(__('billing::portal.error_past_due_booking', [], 'en'));
});

/**
 * @return array{plan_code:string,interval:string,amount_gross:int}
 */
function overageDebtPastDueCheckoutRequest(): array
{
    return ['plan_code' => 'pro', 'interval' => 'monthly', 'amount_gross' => 6000, 'addon_codes' => [], 'seats' => 2];
}

function overageDebtPastDueLiveSubscription(bool $live): void
{
    $gate = Mockery::mock(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate::class);
    $gate->shouldReceive('hasLiveMollieSubscription')->andReturn($live);
    app()->instance(\GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionGate::class, $gate);
}

it('does not start a new checkout while an overage debt stands', function (): void {
    $billable = overageDebtPastDueSubscriber();
    overageDebtPastDueLiveSubscription(true);

    expect(fn () => app(\GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout::class)->handle($billable, overageDebtPastDueCheckoutRequest()))
        ->toThrow(PastDueExpansionNotAllowedException::class, __('billing::portal.error_past_due_booking'));

    expect(ChangeMatrixWorld::$payments)->toBe([]);
});

it('does not start a second subscription while the unpaid one is still running at Mollie', function (): void {
    ChangeMatrixWorld::boot();
    $billable = ChangeMatrixWorld::failRenewal(ChangeMatrixWorld::subscription('none', 'pro', 3, ['storage'], used: 1));
    overageDebtPastDueLiveSubscription(true);

    expect(fn () => app(\GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout::class)->handle($billable, overageDebtPastDueCheckoutRequest()))
        ->toThrow(PastDueExpansionNotAllowedException::class);

    expect(ChangeMatrixWorld::$payments)->toBe([]);
});

it('leaves the checkout open to a past-due customer who has no subscription to pay for — the expired trial', function (): void {
    ChangeMatrixWorld::boot();
    $billable = ChangeMatrixWorld::failRenewal(ChangeMatrixWorld::subscription('none', 'pro', 3, ['storage'], used: 1));
    overageDebtPastDueLiveSubscription(false);

    // Not refused for being past due: it gets as far as the next check, which refuses
    // this request for an interval the plan does not sell.
    expect(fn () => app(\GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout::class)->handle($billable, ['interval' => 'weekly'] + overageDebtPastDueCheckoutRequest()))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException::class);
});

it('keeps the overage lock when a plan change ends the dunning of an unpaid renewal', function (): void {
    ChangeMatrixWorld::boot();
    $billable = ChangeMatrixWorld::failRenewal(ChangeMatrixWorld::subscription('none', 'pro', 3, ['storage'], used: 1));
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[ChargeUsageOverageDirectly::PAST_DUE_KEY] = now('UTC')->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Renewal unpaid AND a debt: the past-due reset is still the way to pay for a period.
    ChangeMatrixWorld::change($billable->refresh(), ['plan_code' => 'enterprise']);

    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        // The period is paid now. The debt is not.
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable))->toBeTrue();
});

it('keeps the overage lock when an operator extends the trial of a past-due subscription', function (): void {
    $billable = overageDebtPastDueSubscriber();

    $billable->extendBillingTrialUntil(now('UTC')->addMonths(3));

    expect($billable->refresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebt($billable))->toBeTrue();
});

it('answers in one place whether a subscription may leave past due', function (): void {
    $locked = [ChargeUsageOverageDirectly::PAST_DUE_KEY => '2026-02-09T01:00:00+00:00'];

    expect(ChargeUsageOverageDirectly::statusAfterRecovery($locked, SubscriptionStatus::Active))->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::statusAfterRecovery($locked, SubscriptionStatus::Trial))->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::statusAfterRecovery([], SubscriptionStatus::Active))->toBe(SubscriptionStatus::Active)
        ->and(ChargeUsageOverageDirectly::statusAfterRecovery(['past_due_since' => 'x'], SubscriptionStatus::Trial))->toBe(SubscriptionStatus::Trial);
});

// ── The operator's retry ────────────────────────────────────────────────────

it('starts a real collection attempt when an operator retries a standing debt, once', function (): void {
    $run = overageDebtPastDueRun();
    $id = $run->fresh()->getKey();

    expect($run->paymentRequests)->toBe(3);

    \Livewire\Livewire::test('mollie-billing::admin.past-due.index')
        ->call('retry', $id)
        ->assertSet('error', null)
        ->assertSet('flash', __('billing::admin.past_due.debt_collection_started', ['name' => $run->fresh()->getBillingName()]));

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    // A payment went out, a day after the cycle gave up — the operator does not wait
    // for the rest period — and it is one of the attempts the debt has.
    expect($run->paymentRequests)->toBe(4)
        ->and($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and((int) ($meta[ChargeUsageOverageDirectly::RECOLLECTIONS_KEY] ?? 0))->toBe(1)
        ->and(overageDebtPastDueOpenNet($run->fresh()))->toBe(370);

    // Clicked again while that payment is on its way: nothing more is asked for.
    \Livewire\Livewire::test('mollie-billing::admin.past-due.index')
        ->call('retry', $id)
        ->assertSet('flash', __('billing::admin.past_due.debt_collection_in_flight', ['name' => $run->fresh()->getBillingName()]));

    expect($run->paymentRequests)->toBe(4)
        ->and((int) ($run->fresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::RECOLLECTIONS_KEY] ?? 0))->toBe(1);

    // It is paid: debt gone, lock gone.
    $run->registerOverageCharge();
    $run->settleOpenOverage();

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($run->overageInvoicedNet())->toBe(370)
        ->and($run->invoiceCount('overage'))->toBe(1);
});

// ── Codes in the portal ─────────────────────────────────────────────────────

dataset('portal codes under an overage debt', [
    'credits' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::Credits, 'credits_payload' => ['Tokens' => 10]]],
    'trial extension' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::TrialExtension, 'trial_extension_days' => 7]],
    'period extension' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::PeriodExtension, 'grant_duration_days' => 14]],
    'plan waiver' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::PlanWaiver, 'waiver_periods' => 2]],
    'recurring discount' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::Recurring, 'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage, 'discount_value' => 20, 'discount_periods' => 3]],
    'single payment' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment, 'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage, 'discount_value' => 50]],
    'access grant' => [['type' => \GraystackIT\MollieBilling\Enums\CouponType::AccessGrant, 'grant_plan_code' => 'enterprise', 'grant_interval' => 'monthly', 'grant_duration_days' => 30]],
]);

it('books nothing that costs money, and does not lift the lock, whatever code is entered in the portal', function (array $attributes): void {
    $billable = overageDebtPastDueSubscriber();
    app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->create(['code' => 'DEBTCODE'] + $attributes);

    app(\GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal::class)->handle($billable, 'DEBTCODE');

    $billable->refresh();

    expect(ChangeMatrixWorld::$payments)->toBe([])
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebt($billable))->toBeTrue()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(overageDebtPastDueOpenNet($billable))->toBe(370);
})->with('portal codes under an overage debt');

it('refuses a product bought with a code while the overage debt is open', function (): void {
    $billable = overageDebtPastDueSubscriber();
    config()->set('mollie-billing-plans.products.credits', ['name' => 'Credits', 'price_net' => 1000]);
    app(\GraystackIT\MollieBilling\Services\Billing\CouponService::class)->create([
        'code' => 'PACKFREE', 'type' => \GraystackIT\MollieBilling\Enums\CouponType::SinglePayment,
        'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage, 'discount_value' => 100,
        'applicable_products' => ['credits'],
    ]);

    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'credits', 'coupon_code' => 'PACKFREE']))
        ->toThrow(PastDueExpansionNotAllowedException::class);

    expect(\GraystackIT\MollieBilling\Models\CouponRedemption::query()->count())->toBe(0);
});

it('refuses a one-time order while a renewal is unpaid, too', function (): void {
    ChangeMatrixWorld::boot();
    $billable = ChangeMatrixWorld::failRenewal(ChangeMatrixWorld::subscription('none', 'pro', 3, ['storage'], used: 1));
    config()->set('mollie-billing-plans.products.credits', ['name' => 'Credits', 'price_net' => 1000]);
    $before = ChangeMatrixWorld::$payments;

    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, ['product_code' => 'credits']))
        ->toThrow(PastDueExpansionNotAllowedException::class, __('billing::portal.error_past_due_booking'));

    expect(ChangeMatrixWorld::$payments)->toBe($before);
});
