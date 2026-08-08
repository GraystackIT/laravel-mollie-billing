<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

/**
 * A (plan, interval) the catalog does not sell has no price, and `basePriceNet()` cannot
 * say so — it answers 0, which every pricing path downstream reads as *free*.
 *
 * `StartSubscriptionCheckout` has guarded this since the interval toggle alone was enough
 * to obtain a paid plan for nothing. The change path is the same kind of surface:
 * `SubscriptionUpdateRequest::from()` is documented as taking request input, so an
 * unvalidated `interval` reaches it. Untreated, the zero made `isFreePlan()` true and took
 * the change into the free-downgrade branch — cancelling the Mollie subscription and
 * issuing a real refund — before the enum cast rejected the value and rolled the local
 * transaction back, leaving a billable that looks subscribed and bills nothing.
 */
beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['analytics'],
            'intervals' => [
                // Monthly only — the interval toggle must not be able to price a year.
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 900, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 9000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);

    config()->set('mollie-billing-plans.addons', [
        'analytics' => [
            'name' => 'Analytics',
            'feature_keys' => [],
            // Monthly only, like the plan.
            'intervals' => ['monthly' => ['price_net' => 500]],
        ],
    ]);
});

function unsoldBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Unsold Ltd',
        'email' => uniqid('unsold-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'mollie_customer_id' => 'cst_unsold',
        'mollie_mandate_id' => 'mdt_unsold',
        // The Mollie subscription id lives in the meta, not in a column.
        'subscription_meta' => ['mollie_subscription_id' => 'sub_unsold', 'seat_count' => 1],
    ], $overrides))->save();

    return $billable->refresh();
}

it('refuses an interval the plan does not sell instead of pricing it at zero', function (): void {
    $billable = unsoldBillable();

    // Nothing may reach Mollie: the point is that the refusal happens before any money
    // moves, not that the money movement is cleaned up afterwards.
    Mollie::shouldReceive('send')->never();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'interval' => 'yearly',
    ]))->toThrow(IntervalNotOfferedException::class);

    $fresh = $billable->fresh();
    expect($fresh->subscription_interval)->toBe(SubscriptionInterval::Monthly)
        ->and($fresh->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe('sub_unsold')
        ->and($fresh->subscription_source)->toBe(SubscriptionSource::Mollie);
});

it('refuses a plan code the catalog does not know', function (): void {
    $billable = unsoldBillable();
    Mollie::shouldReceive('send')->never();

    // An unknown code has no `intervals` at all, so it is the same missing-price case —
    // and without the guard it parked the billable on a nonexistent free plan with
    // mollie_subscription_id dropped.
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'does-not-exist',
    ]))->toThrow(IntervalNotOfferedException::class);

    expect($billable->fresh()->subscription_plan_code)->toBe('pro');
});

it('refuses an addon the target interval does not sell', function (): void {
    $billable = unsoldBillable([
        'subscription_plan_code' => 'starter',
        'active_addon_codes' => ['analytics'],
    ]);
    Mollie::shouldReceive('send')->never();

    // starter sells yearly, analytics does not. Priced at 0, the addon would have been
    // carried into the new interval for free, forever.
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'interval' => 'yearly',
        'addons' => ['analytics' => 1],
    ]))->toThrow(IntervalNotOfferedException::class);
});

it('leaves an unchanged pair alone even when the catalog stopped selling it', function (): void {
    $billable = unsoldBillable([
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Yearly,
    ]);

    // The interval this customer is *on* disappears from the catalog. Refusing to touch
    // their seats would punish them for a config change they did not make, so the guard
    // only fires when plan or interval actually move. (What the zero price then does to
    // the change is a separate matter — this pins only that the new guard stays quiet.)
    config()->set('mollie-billing-plans.plans.starter.intervals', [
        'monthly' => ['base_price_net' => 900, 'seat_price_net' => null, 'included_usages' => []],
    ]);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_unsold', 'status' => 'canceled']);

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 2]))
        ->not->toThrow(IntervalNotOfferedException::class);
});

it('refuses to schedule a change onto an interval that is not sold', function (): void {
    $billable = unsoldBillable();

    // Stored, it would price at 0 when applied at period end — and
    // `isPermanentApplyFailure()` already lists this exception, which is how the gap
    // showed: nothing on this path could throw it.
    expect(fn () => app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'pro',
        'interval' => 'yearly',
    ]))->toThrow(IntervalNotOfferedException::class);

    expect($billable->fresh()->scheduled_change_at)->toBeNull();
});

it('stores the addon payload the target plan can actually host', function (): void {
    $billable = unsoldBillable([
        'active_addon_codes' => ['analytics'],
    ]);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'addons' => ['analytics' => 1],
    ]);

    // starter.allowed_addons excludes analytics, and apply() strips it. Storing the raw
    // request made the portal promise an addon that would silently disappear at period
    // end — the money was always right, the promise was not.
    $stored = $billable->fresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? [];

    expect($stored['addons'])->toBe([]);
});

it('keeps a scheduled change the current seat usage blocks, and tells the operators once', function (): void {
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);

    $billable = unsoldBillable([
        'subscription_plan_code' => 'pro',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_unsold', 'seat_count' => 14],
    ]);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
    ]);

    // starter includes 2 seats and sells none extra; the billable has 14. At period end
    // the validator refuses. Re-thrown, that failed ApplyScheduledChangesJob — which
    // allows a single attempt — every night for as long as the customer kept the seats,
    // and nobody was told: the admin branch was an empty `if` with a "out of scope for
    // this phase" comment.
    $billable->fresh()->forceFill(['scheduled_change_at' => BillingTime::nowUtc()->subMinute()])->save();

    app(ScheduleSubscriptionChange::class)->apply($billable->fresh());

    $fresh = $billable->fresh();

    // Kept, not dropped: removing members makes this very change applicable tomorrow.
    expect($fresh->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->not->toBeNull()
        ->and($fresh->subscription_plan_code)->toBe('pro');

    Notification::assertSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
});

it('still drops a change its own validation can never accept', function (): void {
    $billable = unsoldBillable();

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
    ]);

    // The interval disappears from the catalog between scheduling and applying. That is
    // permanent as far as this billable is concerned, so the change is dropped rather
    // than retried nightly.
    config()->set('mollie-billing-plans.plans.starter.intervals', [
        'yearly' => ['base_price_net' => 9000, 'seat_price_net' => null, 'included_usages' => []],
    ]);

    $billable->fresh()->forceFill(['scheduled_change_at' => BillingTime::nowUtc()->subMinute()])->save();

    app(ScheduleSubscriptionChange::class)->apply($billable->fresh());

    $fresh = $billable->fresh();

    expect($fresh->scheduled_change_at)->toBeNull()
        ->and($fresh->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();
});
