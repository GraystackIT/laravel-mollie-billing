<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
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
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function makeLocalFreeBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now(),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('rejects external apply_at=immediate when mode is EndOfPeriod', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    $billable = makeLocalFreeBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'yearly',
        'apply_at' => 'immediate',
    ]))->toThrow(\RuntimeException::class, 'Immediate plan changes are not allowed.');
});

it('rejects external apply_at=end_of_period when mode is Immediate', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::Immediate);

    $billable = makeLocalFreeBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]))->toThrow(\RuntimeException::class, 'Scheduled plan changes are not allowed.');
});

it('allows ScheduleSubscriptionChange::apply() to re-enter update() when mode is EndOfPeriod', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    $billable = makeLocalFreeBillable();

    // First step: schedule the change at period end (this is the user-initiated path).
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'yearly',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();
    expect($billable->subscription_meta['scheduled_change'])->not->toBeNull();

    // Now simulate the period-end apply — this re-enters update() with apply_at=immediate
    // and must NOT throw, because the validateApplyAt internal-flag bypass kicks in.
    app(ScheduleSubscriptionChange::class)->apply($billable);

    $billable->refresh();
    expect($billable->subscription_interval->value)->toBe('yearly');
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull();
});

it('passes UserChoice mode through unrestricted', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice);

    $billable = makeLocalFreeBillable();

    // immediate works
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'yearly',
        'apply_at' => 'immediate',
    ]);

    $billable->refresh();
    expect($billable->subscription_interval->value)->toBe('yearly');
});
