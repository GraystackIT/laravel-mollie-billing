<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\UsageLimitReached;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Facades\MollieBilling as Billing;
use GraystackIT\MollieBilling\Http\Middleware\RequireActiveSubscription;
use GraystackIT\MollieBilling\Http\Middleware\RequirePlanFeature;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * Two decisions of the maintainer about what `past_due` means.
 *
 * 1. **No further overage.** Past due — for an overage debt or for an unpaid renewal —
 *    leaves the included quota usable and nothing beyond it: a booking that would take
 *    a wallet below zero (or further below) is refused the way it is for a billable
 *    that does not allow overage at all, through the same exception and the same event.
 * 2. **Access stays for a customer who paid the plan.** Past due *only* for an overage
 *    debt keeps the application open in the scope of the paid plan. Booking and overage
 *    are what is locked. With a renewal unpaid as well, nothing changes: no access.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

/** January ran 37 Tokens over; the charge fails three times; the February renewal is paid. */
function pastDueUsageDebtOnly(): UsageLongRunHarness
{
    UsageLongRunHarness::definePlan();
    config()->set('mollie-billing-plans.plans.pro.feature_keys', ['reports']);
    $run = UsageLongRunHarness::boot();

    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-24T12:00:00Z');
    $run->use('Tokens', 137);

    for ($day = Carbon::parse('2026-01-25'); $day->lessThanOrEqualTo(Carbon::parse('2026-02-09')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
    }

    for ($attempt = 0; $attempt < 6 && ($id = $run->openOveragePayment()) !== null; $attempt++) {
        $run->travelTo(Carbon::now('UTC')->addMinutes(10));
        $run->resolveOverage($id, 'failed');
    }

    $run->travelTo('2026-02-10T09:02:00Z');
    $run->renew('2026-02-10T09:00:00Z');

    expect(ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($run->fresh()))->toBeTrue();

    return $run;
}

function pastDueUsageOpenDebt(UsageLongRunHarness $run): int
{
    return (int) ($run->fresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY]['amount_net'] ?? 0);
}

// ── 1. No further overage ───────────────────────────────────────────────────

it('lets a past-due customer use the included quota and refuses everything beyond it', function (): void {
    $run = pastDueUsageDebtOnly();
    Event::fake([UsageLimitReached::class]);

    // The renewal handed out the whole quota of the new period: the debt is kept apart
    // as money, it is not netted against units.
    expect($run->balance('Tokens'))->toBe(100)
        ->and(pastDueUsageOpenDebt($run))->toBe(370)
        ->and($run->fresh()->canChargeBillingOverage('Tokens'))->toBeFalse()
        ->and($run->fresh()->hasBillingQuotaLeft('Tokens'))->toBeTrue();

    // Inside the quota: fine.
    $run->use('Tokens', 60);
    expect($run->balance('Tokens'))->toBe(40);
    Event::assertNotDispatched(UsageLimitReached::class);

    // Partly covered (40 left, 50 asked): refused as a whole, as for a billable that
    // does not allow overage. Nothing is booked.
    expect(fn () => $run->use('Tokens', 50))->toThrow(UsageLimitExceededException::class);
    expect($run->balance('Tokens'))->toBe(40);
    Event::assertDispatched(UsageLimitReached::class, 1);

    // Exactly the rest is still fine, and then the gate says no.
    $run->use('Tokens', 40);
    expect($run->balance('Tokens'))->toBe(0)
        ->and($run->fresh()->hasBillingQuotaLeft('Tokens'))->toBeFalse();
    expect(fn () => $run->use('Tokens', 1))->toThrow(UsageLimitExceededException::class);

    // Another type with units left is not affected.
    $run->use('SMS', 10);
    expect($run->fresh()->hasBillingQuotaLeft('SMS'))->toBeTrue();

    // The debt is what it was.
    expect(pastDueUsageOpenDebt($run))->toBe(370)
        ->and($run->balance('Tokens'))->toBe(0);
});

it('allows overage again once the debt is paid and the subscription is active', function (): void {
    $run = pastDueUsageDebtOnly();
    $run->use('Tokens', 100);
    expect(fn () => $run->use('Tokens', 5))->toThrow(UsageLimitExceededException::class);

    // March: plan and debt are collected and paid.
    $run->travelTo('2026-03-10T09:02:00Z');
    $run->renew('2026-03-10T09:00:00Z');
    $run->settleOpenOverage();

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($run->fresh()->canChargeBillingOverage('Tokens'))->toBeTrue();

    $run->use('Tokens', 105);
    expect($run->balance('Tokens'))->toBe(-5);
});

it('refuses further usage on a wallet that is already negative when a renewal fails', function (): void {
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-24T12:00:00Z');
    $run->use('Tokens', 120);
    expect($run->balance('Tokens'))->toBe(-20);

    // The renewal fails: ordinary past due.
    $run->travelTo('2026-02-10T09:02:00Z');
    $run->renew('2026-02-10T09:00:00Z', status: 'failed');

    expect($run->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($run->fresh()->hasBillingQuotaLeft('Tokens'))->toBeFalse();

    // Not one more unit on the wallet that is below zero …
    expect(fn () => $run->use('Tokens', 1))->toThrow(UsageLimitExceededException::class);
    expect($run->balance('Tokens'))->toBe(-20);

    // … while the type that still has units is usable, up to what it has.
    $run->use('SMS', 50);
    expect(fn () => $run->use('SMS', 1))->toThrow(UsageLimitExceededException::class);
});

// ── 2. Access ───────────────────────────────────────────────────────────────

it('keeps the application open, in the scope of the paid plan, for a customer who only owes overage', function (): void {
    $run = pastDueUsageDebtOnly();
    $billable = $run->fresh();
    Billing::resolveBillableUsing(fn () => $billable);

    Route::middleware(RequireActiveSubscription::class)->get('/pd-guarded', fn () => response('OK'));
    Route::middleware(RequirePlanFeature::class.':reports')->get('/pd-feature', fn () => response('FEATURE'));
    Route::middleware(RequirePlanFeature::class.':not-in-plan')->get('/pd-other', fn () => response('OTHER'));

    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and(Billing::features()->has($billable, 'reports'))->toBeTrue()
        ->and($billable->hasPlanFeature('reports'))->toBeTrue()
        ->and(Billing::features()->has($billable, 'not-in-plan'))->toBeFalse();

    $this->get('/pd-guarded')->assertOk()->assertSee('OK');
    $this->get('/pd-feature')->assertOk()->assertSee('FEATURE');
    // The plan's scope, not more.
    $this->get('/pd-other')->assertRedirect();

    expect(\Illuminate\Support\Facades\Blade::render('@planFeature(\'reports\')yes @else no @endplanFeature'))->toContain('yes');
});

it('still closes the application when a renewal is unpaid as well', function (): void {
    $run = pastDueUsageDebtOnly();

    $run->travelTo('2026-03-10T09:02:00Z');
    $run->renew('2026-03-10T09:00:00Z', status: 'failed');

    $billable = $run->fresh();
    Billing::resolveBillableUsing(fn () => $billable);
    Route::middleware(RequireActiveSubscription::class)->get('/pd-guarded-2', fn () => response('OK'));

    expect(ChargeUsageOverageDirectly::isPastDueForOverageDebt($billable))->toBeTrue()
        ->and(ChargeUsageOverageDirectly::isPastDueForOverageDebtOnly($billable))->toBeFalse()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();

    $this->get('/pd-guarded-2')->assertRedirect(route(BillingRoute::name('index')));
});

it('still treats a customer who only owes overage as subscribed: no second checkout, the portal stays theirs', function (): void {
    $run = pastDueUsageDebtOnly();
    $billable = $run->fresh();

    expect(fn () => app(\GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'pro', 'interval' => 'monthly', 'amount_gross' => 1190, 'addon_codes' => [], 'seats' => 1,
    ]))->toThrow(\GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException::class);

    expect($run->paymentRequests)->toBe(3);
});

// ── The next charge, as the customer is shown it ────────────────────────────

it('announces the next renewal with the open overage debt, and the total is what the renewal collects', function (): void {
    $run = pastDueUsageDebtOnly();
    $summary = app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($run->fresh());

    // 10.00 + 19 % for the plan, and the 37 units at 10 ct that are still owed: 3.70 + 19 %.
    expect($summary['state'])->toBe(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::STATE_SCHEDULED)
        ->and($summary['date']?->toDateString())->toBe('2026-03-10')
        ->and($summary['gross'])->toBe(1190)
        ->and($summary['overage_debt'])->toBe(['net' => 370, 'gross' => 440])
        ->and($summary['total_gross'])->toBe(1630);

    // March: what Mollie is actually asked for.
    $before = array_keys($run->overagePayments);
    $run->travelTo('2026-03-10T09:02:00Z');
    $run->renew('2026-03-10T09:00:00Z', '11.90');

    $new = array_values(array_diff(array_keys($run->overagePayments), $before));

    expect($new)->toHaveCount(1)
        ->and(1190 + (int) $run->overagePayments[$new[0]]['gross'])->toBe($summary['total_gross']);

    // While that payment is on its way it is not part of the *next* renewal …
    $inFlight = app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($run->fresh());
    expect($inFlight['state'])->toBe(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::STATE_SCHEDULED)
        ->and($inFlight['overage_debt'])->toBeNull()
        ->and($inFlight['total_gross'])->toBe(1190);

    // … and once it is paid there is nothing but the plan.
    $run->settleOpenOverage();
    $paid = app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($run->fresh());

    expect($paid['overage_debt'])->toBeNull()
        ->and($paid['total_gross'])->toBe($paid['gross']);
});

it('keeps saying "past due" instead of a next charge when a renewal is unpaid', function (): void {
    $run = pastDueUsageDebtOnly();
    $run->travelTo('2026-03-10T09:02:00Z');
    $run->renew('2026-03-10T09:00:00Z', status: 'failed');

    $summary = app(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::class)->for($run->fresh());

    expect($summary['state'])->toBe(\GraystackIT\MollieBilling\Services\Billing\NextChargeSummary::STATE_PAST_DUE)
        ->and($summary['date'])->toBeNull();
});
