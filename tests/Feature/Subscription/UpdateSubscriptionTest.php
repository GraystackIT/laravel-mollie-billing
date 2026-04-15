<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'included_usages' => [],
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 5,
        'included_usages' => [],
        'feature_keys' => ['dashboard', 'pro-feature'],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.print-gateway', [
        'name' => 'Print Gateway',
        'feature_keys' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['price_net' => 990],
            'yearly' => ['price_net' => 9900],
        ],
    ]);
});

function makeLocalProBillable(int $seats = 5, array $addons = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['seat_count' => $seats],
    ])->save();

    return $billable->refresh();
}

it('increases seats on a Local subscription and fires SeatsChanged', function (): void {
    Event::fake([SeatsChanged::class, SubscriptionUpdated::class]);

    $billable = makeLocalProBillable(5);

    $result = app(UpdateSubscription::class)->update($billable, ['seats' => 7]);

    $billable->refresh();

    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(7);
    expect($result['seatsChanged'])->toBeTrue();
    expect($result['events'])->toContain(SeatsChanged::class);

    Event::assertDispatched(SeatsChanged::class, function (SeatsChanged $event) use ($billable): bool {
        return $event->billable->getKey() === $billable->getKey()
            && $event->oldCount === 5
            && $event->newCount === 7;
    });
});

it('changes the plan from free to pro and fires PlanChanged', function (): void {
    Event::fake([PlanChanged::class, SubscriptionUpdated::class]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('pro');
    expect($result['planChanged'])->toBeTrue();

    Event::assertDispatched(PlanChanged::class, function (PlanChanged $event) use ($billable): bool {
        return $event->billable->getKey() === $billable->getKey()
            && $event->newPlan === 'pro';
    });
});

it('enables an addon and records it in diff.addonsAdded', function (): void {
    Event::fake([AddonEnabled::class, SubscriptionUpdated::class]);

    $billable = makeLocalProBillable(5, []);

    $result = app(UpdateSubscription::class)->update($billable, [
        'addons' => ['print-gateway' => 1],
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toContain('print-gateway');
    expect($result['addonsAdded'])->toContain('print-gateway');

    Event::assertDispatched(AddonEnabled::class, function (AddonEnabled $event): bool {
        return $event->addonCode === 'print-gateway';
    });
});

it('delegates to schedule when applyAt is end_of_period and does not modify current state', function (): void {
    Event::fake([SubscriptionChangeScheduled::class, SeatsChanged::class]);

    $billable = makeLocalProBillable(5);

    $result = app(UpdateSubscription::class)->update($billable, [
        'seats' => 3,
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();

    // Seats are not modified yet.
    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(5);
    expect($billable->subscription_meta['scheduled_change']['seats'] ?? null)->toBe(3);
    expect($result)->toHaveKey('scheduledFor');

    Event::assertDispatched(SubscriptionChangeScheduled::class);
    Event::assertNotDispatched(SeatsChanged::class);
});

it('applies a scheduled downgrade via ScheduleSubscriptionChange::apply', function (): void {
    $billable = makeLocalProBillable(5);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'seats' => 3,
    ]);

    $billable->refresh();
    expect($billable->subscription_meta['scheduled_change']['seats'] ?? null)->toBe(3);

    app(ScheduleSubscriptionChange::class)->apply($billable);

    $billable->refresh();

    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(3);
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull();
});
