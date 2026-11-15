<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionChangeCancelled;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            // No seat_price_net: this plan sells no extra seats.
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function schedulableBillable(int $seatCount = 14, int $usedSeats = 10): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Sched Co',
        'email' => 'sched@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_sched',
        'mollie_mandate_id' => 'mdt_sched',
    ]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_sched',
            'seat_count' => $seatCount,
            'used_seats' => $usedSeats,
        ],
    ])->save();

    return $b->refresh();
}

it('preserves paid extra seats when scheduling an interval change without explicit seats', function (): void {
    $billable = schedulableBillable(seatCount: 14, usedSeats: 10);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();
    $change = $billable->getBillingSubscriptionMeta()['scheduled_change'];

    // Was max(usedSeats, includedSeats) = max(10, 5) = 10 → four paid seats vanished.
    expect($change['seats'])->toBe(14);
    expect($change['seats_auto_derived'])->toBeTrue();
});

it('respects an explicitly requested seat count', function (): void {
    $billable = schedulableBillable(seatCount: 14, usedSeats: 10);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'interval' => 'yearly',
        'seats' => 11,
        'apply_at' => 'end_of_period',
    ]);

    $change = $billable->refresh()->getBillingSubscriptionMeta()['scheduled_change'];

    expect($change['seats'])->toBe(11);
    expect($change)->not->toHaveKey('seats_auto_derived');
});

it('re-derives auto-derived seats at apply time so a plan without extra seats does not throw', function (): void {
    // 14 seats on Pro, moving to Starter which sells no extra seats and includes 2.
    // Replaying 14 as an explicit count bypasses the validator's clamp and throws
    // SeatDowngradeRequiredException for a change that was accepted when scheduled.
    $billable = schedulableBillable(seatCount: 14, usedSeats: 2);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();
    // A plan change lands on the seats in use or included — two — and that is what is
    // stored for display; it is re-derived when the change is applied.
    expect($billable->getBillingSubscriptionMeta()['scheduled_change']['seats'])->toBe(2);
    expect($billable->getBillingSubscriptionMeta()['scheduled_change']['seats_auto_derived'])->toBeTrue();

    $meta = $billable->getBillingSubscriptionMeta();
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'tr_sched', 'status' => 'open']);

    // apply() re-throws, so reaching the assertions at all proves no
    // SeatDowngradeRequiredException was raised for a change accepted at schedule time.
    app(ScheduleSubscriptionChange::class)->apply($billable->refresh());

    $billable->refresh();

    // The downgrade defers behind a Mollie prorata charge (Phase 2 applies the switch),
    // so the re-derived seat count shows up in the Phase-1 snapshot: clamped to what
    // Starter allows, not the 14 that were stored at schedule time.
    expect($billable->getBillingSubscriptionMeta()['pending_plan_change']['seats'])->toBe(2);
    expect($billable->getBillingSubscriptionMeta()['pending_plan_change']['plan_code'])->toBe('starter');
});

it('clears a scheduled change when the subscription is cancelled', function (): void {
    Event::fake([SubscriptionChangeCancelled::class]);

    $billable = schedulableBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]);

    expect($billable->refresh()->scheduled_change_at)->not->toBeNull();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_sched', 'status' => 'canceled']);

    app(CancelSubscription::class)->handle($billable->refresh(), immediately: false);

    $billable->refresh();

    // Otherwise the period-end dispatcher rewrites the plan and can charge overage
    // against a customer who has already left.
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
    expect($billable->scheduled_change_at)->toBeNull();

    Event::assertDispatched(SubscriptionChangeCancelled::class);
});

it('drops instead of applying a change once the subscription is no longer running', function (): void {
    $billable = schedulableBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'apply_at' => 'end_of_period',
    ]);

    // Simulate Pass 3 having already expired the billable in the same run.
    $billable->refresh()->forceFill(['subscription_status' => SubscriptionStatus::Expired])->save();

    Mollie::shouldReceive('send')->never();

    app(ScheduleSubscriptionChange::class)->apply($billable->refresh());

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('pro');
    // Cleared, so the daily dispatcher stops re-failing this job forever.
    expect($billable->scheduled_change_at)->toBeNull();
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('scheduled_change');
});
