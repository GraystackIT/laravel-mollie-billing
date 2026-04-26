<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\ValidateSubscriptionChange;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletPlanChangeAdjuster;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyUpdateSubscription;

beforeEach(function (): void {
    SpyUpdateSubscription::$calls = [];

    $this->app->bind(UpdateSubscription::class, function ($app): UpdateSubscription {
        return new SpyUpdateSubscription(
            $app->make(CouponService::class),
            $app->make(PreviewService::class),
            $app->make(SubscriptionCatalogInterface::class),
            $app->make(VatCalculationService::class),
            $app->make(ValidateSubscriptionChange::class),
            $app->make(ScheduleSubscriptionChange::class),
            $app->make(WalletPlanChangeAdjuster::class),
        );
    });

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
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 990, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 9900, 'included_usages' => []],
        ],
    ]);
});

function makeMollieProBillableForDowngrade(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_456',
        'mollie_mandate_id' => 'mdt_test_456',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_test_456'],
    ])->save();

    return $billable->refresh();
}

it('cancels the Mollie subscription and switches source to Local on immediate Mollie→Free downgrade', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::Immediate);

    $billable = makeMollieProBillableForDowngrade();

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'apply_at' => 'immediate',
    ]);

    $billable->refresh();

    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_plan_code)->toBe('free');
    expect($billable->subscription_ends_at)->toBeNull();
    expect($billable->subscription_meta['mollie_subscription_id'] ?? null)->toBeNull();

    // The spy records cancel calls.
    $cancelCalls = collect(SpyUpdateSubscription::$calls)->filter(fn ($c) => $c[0] === 'cancel');
    expect($cancelCalls)->not->toBeEmpty();
});

it('schedules the change for end-of-period in EndOfPeriod mode without immediate switch', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    $billable = makeMollieProBillableForDowngrade();

    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]);

    $billable->refresh();

    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_meta['scheduled_change']['plan_code'] ?? null)->toBe('free');
});

it('applies a scheduled Mollie→Free downgrade end-to-end', function (): void {
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::EndOfPeriod);

    $billable = makeMollieProBillableForDowngrade();

    // Schedule for end of period.
    app(UpdateSubscription::class)->update($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'apply_at' => 'end_of_period',
    ]);

    // Now simulate the period-end apply.
    app(ScheduleSubscriptionChange::class)->apply($billable);

    $billable->refresh();

    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);
    expect($billable->subscription_plan_code)->toBe('free');
    expect($billable->subscription_meta['scheduled_change'] ?? null)->toBeNull();
});
