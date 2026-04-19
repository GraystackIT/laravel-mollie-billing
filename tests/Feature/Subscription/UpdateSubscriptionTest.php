<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\AddonDisabled;
use GraystackIT\MollieBilling\Events\AddonEnabled;
use GraystackIT\MollieBilling\Events\PlanChanged;
use GraystackIT\MollieBilling\Events\SeatsChanged;
use GraystackIT\MollieBilling\Events\SubscriptionChangeScheduled;
use GraystackIT\MollieBilling\Events\SubscriptionUpdated;
use GraystackIT\MollieBilling\Exceptions\SeatDowngradeRequiredException;
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
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 5,
        'feature_keys' => ['dashboard', 'pro-feature'],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
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

// ── Bug 3: Seats auto-derived on plan change ──

it('auto-derives seat count from new plan when no explicit seats given', function (): void {
    // Pro has 5 included seats, used_seats = 3 → max(3, 5) = 5
    $billable = makeLocalProBillable(10); // old seat_count = 10
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 3;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // free has 1 included seat, used_seats = 3, seat_price_net = null → should throw
})->throws(SeatDowngradeRequiredException::class);

it('blocks plan change when used seats exceed new plan without extra seat support', function (): void {
    $billable = makeLocalProBillable(5);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 3;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Free has 1 included seat, no seat_price_net → must throw
    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]))->toThrow(SeatDowngradeRequiredException::class);
});

it('allows plan change with extra seats when new plan supports them', function (): void {
    // Set up a plan with extra seat support and fewer included seats
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 2,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => 200, 'included_usages' => []],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => 2000, 'included_usages' => []],
        ],
    ]);

    $billable = makeLocalProBillable(5);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 4;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Starter has 2 included seats + seat_price_net = 200 → extra seats allowed
    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    // max(used_seats=4, included=2) = 4
    expect($billable->subscription_meta['seat_count'])->toBe(4);
    expect($result['seatsChanged'])->toBeTrue();
});

// ── Bug 2: Incompatible addons stripped on plan change ──

it('strips incompatible addons when changing to a plan that does not allow them', function (): void {
    Event::fake([AddonDisabled::class, PlanChanged::class, SubscriptionUpdated::class]);

    $billable = makeLocalProBillable(5, ['print-gateway']);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 0;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toBe([]);
    expect($result['addonsRemoved'])->toContain('print-gateway');

    Event::assertDispatched(AddonDisabled::class, function (AddonDisabled $event): bool {
        return $event->addonCode === 'print-gateway';
    });
});

it('keeps compatible addons when changing plans', function (): void {
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 10,
        'feature_keys' => ['dashboard', 'pro-feature', 'enterprise'],
        'allowed_addons' => ['print-gateway'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    $billable = makeLocalProBillable(5, ['print-gateway']);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    expect($billable->active_addon_codes)->toContain('print-gateway');
    expect($result['addonsRemoved'])->toBe([]);
});

// ── Bug 4: Wallet adjustment on plan change ──

it('credits wallet difference on upgrade to plan with more usage quota', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);
    config()->set('mollie-billing-plans.plans.free.intervals.monthly.included_usages', ['Tokens' => 10]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1, 'used_seats' => 0],
    ])->save();

    // Seed the wallet with 10 tokens (free plan quota)
    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->deposit(10);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
    ]);

    $wallet->refresh();

    // Old included: 10, New included: 100 → diff = +90 credited
    expect((int) $wallet->balanceInt)->toBe(100);
});

it('caps wallet balance on downgrade to plan with less usage quota', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.included_usages', ['Tokens' => 100]);

    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => ['dashboard'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 500, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
            'yearly' => ['base_price_net' => 5000, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 50]],
        ],
    ]);

    $billable = makeLocalProBillable(1);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['used_seats'] = 0;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    // Seed wallet: 100 included, 40 used → balance = 60 (within new quota, no overage)
    $wallet = $billable->createWallet(['name' => 'Tokens', 'slug' => 'Tokens']);
    $wallet->deposit(60);

    $result = app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'basic',
        'interval' => 'monthly',
    ]);

    $wallet->refresh();

    // Old included: 100, New included: 50 → diff = -50
    // Balance: max(0, 60 + (-50)) = 10
    expect((int) $wallet->balanceInt)->toBe(10);
});
