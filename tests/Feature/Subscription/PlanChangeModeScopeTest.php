<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// `plan_change_mode` is named, documented and configured for *plan changes*. It used to
// govern every call into `update()`, so on `end_of_period` a seat adjustment and an addon
// toggle — neither of which sends an `apply_at` — threw "Immediate plan changes are not
// allowed." Every seat and addon control in the portal was dead.

beforeEach(function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);
    config()->set('mollie-billing-plans.addons.extra', [
        'name' => 'Extra',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 0]],
    ]);
    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter',
            'tier' => 1,
            'included_seats' => 5,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 900, 'seat_price_net' => 0, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 5,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 2900, 'seat_price_net' => 0, 'included_usages' => []]],
        ],
    ]);
});

function modeBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Mode Ltd',
        'email' => uniqid('mode-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(8),
        'mollie_customer_id' => 'cst_mode',
        'mollie_mandate_id' => 'mdt_mode',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_mode', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('lets a seat change through under end_of_period', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_mode', 'status' => 'active']);

    $billable = modeBillable();

    $billable->syncBillingSeats(3);

    expect($billable->fresh()->getBillingSeatCount())->toBe(3);
});

it('lets an addon toggle through under end_of_period', function (): void {
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_mode', 'status' => 'active']);

    $billable = modeBillable();

    $billable->enableBillingAddon('extra');
    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe(['extra']);

    $billable->disableBillingAddon('extra');
    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('schedules changeBillingPlan() instead of refusing it', function (): void {
    // The documented API says nothing about `apply_at`, and "say nothing" under this mode
    // means "schedule it" — not "throw an untyped RuntimeException at the caller".
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_mode', 'status' => 'active']);

    $billable = modeBillable();

    $billable->changeBillingPlan('pro', 'monthly');

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('starter')
        ->and($billable->scheduled_change_at)->not->toBeNull()
        ->and($billable->getBillingSubscriptionMeta()['scheduled_change']['plan_code'] ?? null)->toBe('pro');
});

it('still refuses a plan change that explicitly asks for immediate', function (): void {
    $billable = modeBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'apply_at' => 'immediate',
    ]))->toThrow(RuntimeException::class, 'Immediate plan changes are not allowed.');
});

it('still refuses a scheduled plan change under immediate mode', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::Immediate);

    $billable = modeBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'pro',
        'apply_at' => 'end_of_period',
    ]))->toThrow(RuntimeException::class, 'Scheduled plan changes are not allowed.');
});

it('remembers whether the caller named an apply_at', function (): void {
    expect(SubscriptionUpdateRequest::from(['plan_code' => 'pro'])->applyAtExplicit)->toBeFalse()
        ->and(SubscriptionUpdateRequest::from(['plan_code' => 'pro'])->applyAt)->toBe('immediate')
        ->and(SubscriptionUpdateRequest::from(['plan_code' => 'pro', 'apply_at' => 'immediate'])->applyAtExplicit)->toBeTrue()
        ->and((new SubscriptionUpdateRequest(planCode: 'pro'))->applyAtExplicit)->toBeFalse()
        ->and((new SubscriptionUpdateRequest(planCode: 'pro', applyAt: 'end_of_period'))->applyAtExplicit)->toBeTrue();
});
