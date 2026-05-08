<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Support\ConfigSubscriptionCatalog;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\ChangePlan;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'seat_price_net' => null,
                // Pro has trial_days set — but on a plan-change this MUST be ignored.
                'trial_days' => 14,
                'included_usages' => [],
            ],
            'yearly' => [
                'base_price_net' => 20000,
                'seat_price_net' => null,
                'trial_days' => 30,
                'included_usages' => [],
            ],
        ],
    ]);
});

it('does not start a trial when an Active billable changes to a plan with trial_days', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => [],
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_x'],
    ])->save();
    $billable->refresh();

    $beforeTrial = $billable->trial_ends_at;

    // Stub UpdateSubscription so this test stays focused on trial behaviour.
    $stub = Mockery::mock(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class);
    $stub->shouldReceive('update')->andReturnUsing(function ($billable, $spec) {
        $billable->forceFill([
            'subscription_plan_code' => $spec['plan_code'] ?? $billable->subscription_plan_code,
            'subscription_interval' => isset($spec['interval'])
                ? SubscriptionInterval::from($spec['interval'])
                : $billable->subscription_interval,
        ])->save();

        return [];
    });
    $this->app->instance(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class, $stub);

    app(ChangePlan::class)->handle($billable, 'pro', 'monthly');

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->trial_ends_at)->toEqual($beforeTrial);
    expect($billable->subscription_plan_code)->toBe('pro');
});

it('preserves trial_ends_at on a Trial billable changing plans', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => 'tc@x.test']);
    $trialEnds = BillingTime::nowUtc()->addDays(7);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => $trialEnds,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDay(),
        'active_addon_codes' => [],
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_x'],
    ])->save();
    $billable->refresh();

    $stub = Mockery::mock(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class);
    $stub->shouldReceive('update')->andReturnUsing(function ($billable, $spec) {
        $billable->forceFill([
            'subscription_plan_code' => $spec['plan_code'] ?? $billable->subscription_plan_code,
        ])->save();

        return [];
    });
    $this->app->instance(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class, $stub);

    app(ChangePlan::class)->handle($billable, 'pro', 'monthly');

    $billable->refresh();

    expect($billable->trial_ends_at?->toIso8601String())->toBe($trialEnds->toIso8601String());
    expect($billable->subscription_plan_code)->toBe('pro');
});

it('ChangePlan never invokes catalog->trialDays()', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => [],
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_x'],
    ])->save();
    $billable->refresh();

    // Spy that subclasses the default catalog (so all other methods stay
    // functional via inheritance) and counts calls to trialDays().
    $spy = new class extends ConfigSubscriptionCatalog {
        public int $trialDaysCalls = 0;

        public function trialDays(string $planCode, string $interval): int
        {
            $this->trialDaysCalls++;

            return parent::trialDays($planCode, $interval);
        }
    };
    $this->app->instance(SubscriptionCatalogInterface::class, $spy);

    $stub = Mockery::mock(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class);
    $stub->shouldReceive('update')->andReturnUsing(function ($billable, $spec) {
        $billable->forceFill([
            'subscription_plan_code' => $spec['plan_code'] ?? $billable->subscription_plan_code,
        ])->save();

        return [];
    });
    $this->app->instance(\GraystackIT\MollieBilling\Services\Billing\UpdateSubscription::class, $stub);

    app(ChangePlan::class)->handle($billable, 'pro', 'monthly');

    expect($spy->trialDaysCalls)->toBe(0);
});
