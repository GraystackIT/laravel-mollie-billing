<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Events\SubscriptionChangeApplyFailed;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\ScheduledChangeNotAppliedNotification;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\ChangeMatrixWorld as World;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * A scheduled change that cannot be applied is dealt with by the package, not by an admin.
 *
 *  - Something that may work tomorrow (Mollie unreachable, a lock, a payment that could
 *    not be created) is tried again on the following nights, a limited number of times.
 *    Nobody is told while that runs.
 *  - Once the attempts are used up the change is dropped, the customer is told, and only
 *    then the admins.
 *  - Something the validator refuses is not retried: dropped at once, the customer told
 *    why. No admin is needed for that.
 */
beforeEach(function (): void {
    World::boot();

    $this->admin = TestBillable::create(['name' => 'Operator', 'email' => 'operator-retry@x.test']);
    MollieBilling::notifyAdminUsing(fn (): array => [$this->admin]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

/** Pro → Enterprise scheduled for the period end, then pulled forward to "tonight". */
function autoRetryScheduledUpgrade(): TestBillable
{
    $billable = World::subscription('none', 'pro', 2, []);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'enterprise', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    return $billable->refresh();
}

it('tries again the next night without telling anyone, and applies the change when it works', function (): void {
    $billable = autoRetryScheduledUpgrade();
    Event::fake([SubscriptionChangeApplyFailed::class]);

    // Night one: Mollie cannot create the prorata payment.
    $this->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    World::$failPayments = true;
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'] ?? null)->toBe(1)
        ->and($billable->scheduled_change_at)->not->toBeNull();

    Notification::assertNothingSentTo($this->admin);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
    Event::assertNotDispatched(SubscriptionChangeApplyFailed::class);

    // Night two: it goes through.
    $this->travelTo(Carbon::parse('2026-01-21 02:00:00', 'UTC'));
    World::$failPayments = false;
    app(ScheduleSubscriptionChange::class)->apply($billable);
    World::settle($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionPlanCode())->toBe('enterprise')
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();

    Notification::assertNothingSentTo($this->admin);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
});

it('drops the change after the last attempt, tells the customer, and only then an admin', function (): void {
    $billable = autoRetryScheduledUpgrade();
    $heldBeforeScheduling = '60.00';
    World::$failPayments = true;

    expect(ScheduleSubscriptionChange::MAX_APPLY_ATTEMPTS)->toBe(3)
        // Announced to Mollie when it was scheduled.
        ->and(World::$heldValue)->toBe('240.00');

    foreach (['2026-01-20', '2026-01-21'] as $night) {
        $this->travelTo(Carbon::parse($night.' 02:00:00', 'UTC'));
        app(ScheduleSubscriptionChange::class)->apply($billable);
    }

    Notification::assertNothingSentTo($this->admin);
    Notification::assertNotSentTo($billable, ScheduledChangeNotAppliedNotification::class);
    expect($billable->refresh()->getBillingSubscriptionMeta()['scheduled_change']['apply_attempts'])->toBe(2);

    $this->travelTo(Carbon::parse('2026-01-22 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    // Dropped — and Mollie put back to the plan the customer stays on.
    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->scheduled_change_at)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$heldValue)->toBe($heldBeforeScheduling);

    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->planCode === 'enterprise' && $n->reason === 'attempts_exhausted');
    Notification::assertSentTo($this->admin, AdminPlanChangeFailedNotification::class, fn ($n): bool => ($n->context['attempts'] ?? null) === 3);
    Notification::assertSentToTimes($this->admin, AdminPlanChangeFailedNotification::class, 1);

    expect(BillingAuditEntry::model()::query()->where('event', 'subscription_change_apply_failed')->count())->toBe(1);

    // Nothing left to run on the fourth night.
    $this->travelTo(Carbon::parse('2026-01-23 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    Notification::assertSentToTimes($billable, ScheduledChangeNotAppliedNotification::class, 1);
});

it('does not retry what the validator refuses: dropped at once, the customer told why, no admin', function (): void {
    // Starter sells no seats and includes two; four members are assigned by the time
    // the change is due.
    config()->set('mollie-billing-plans.plans.starter.intervals.monthly.seat_price_net', null);
    $billable = World::subscription('none', 'pro', 4, [], used: 2);

    app(UpdateSubscription::class)->update($billable, ['plan_code' => 'starter', 'interval' => 'monthly', 'apply_at' => 'end_of_period']);

    $meta = $billable->refresh()->getBillingSubscriptionMeta();
    $meta['used_seats'] = 4;
    $billable->forceFill(['subscription_meta' => $meta])->save();
    $held = '72.00'; // Pro + 2 seats, what Mollie held before the change was announced

    $this->travelTo(Carbon::parse('2026-01-20 02:00:00', 'UTC'));
    app(ScheduleSubscriptionChange::class)->apply($billable);
    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull()
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and(World::$heldValue)->toBe($held);

    Notification::assertSentTo($billable, ScheduledChangeNotAppliedNotification::class, fn ($n): bool => $n->planCode === 'starter' && $n->reason === 'seats_in_use');
    Notification::assertNothingSentTo($this->admin);

    expect(BillingAuditEntry::model()::query()->where('event', 'subscription_change_apply_failed')->count())->toBe(1);
});

it('has the customer mail in both languages for every reason', function (string $locale) {
    app()->setLocale($locale);

    foreach (['attempts_exhausted', 'seats_in_use', 'not_available', 'payment_outstanding', 'default'] as $reason) {
        $key = 'billing::notifications.scheduled_change_not_applied.reasons.'.$reason;
        expect(trans($key))->not->toBe($key);
    }

    expect(trans('billing::notifications.scheduled_change_not_applied.subject'))->not->toContain('billing::');
})->with(['en', 'de']);
