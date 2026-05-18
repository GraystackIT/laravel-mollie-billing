<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1900,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => [],
            ],
            'yearly' => [
                'base_price_net' => 19000,
                'seat_price_net' => null,
                'trial_days' => 30,
                'included_usages' => [],
            ],
        ],
    ]);
});

it('configures Mollie startDate = now + trialDays when trial_days is set', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trial@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_trial',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->andReturnUsing(function ($request) use (&$captured) {
            $captured = $request;

            return (object) ['id' => 'sub_trial'];
        });

    $expectedStart = BillingTime::nowUtc()->addDays(14)->toDateString();

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'trial_days' => 14,
    ]);

    expect($captured)->toBeInstanceOf(CreateSubscriptionRequest::class);

    $reflected = new ReflectionObject($captured);
    $startDateProp = $reflected->getProperty('startDate');
    $startDateProp->setAccessible(true);
    $startDate = $startDateProp->getValue($captured);

    // Mollie\Api\Http\Data\Date stringifies to YYYY-MM-DD
    expect((string) $startDate)->toBe($expectedStart);
});

it('keeps the recurring amount at the full plan price during a trial', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trial@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_trial',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_trial'];
    });

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'trial_days' => 14,
    ]);

    $reflected = new ReflectionObject($captured);
    $amountProp = $reflected->getProperty('amount');
    $amountProp->setAccessible(true);
    $money = $amountProp->getValue($captured);

    // 1900 net + 20% AT VAT = 2280 gross = 22.80
    expect($money->value)->toBe('22.80');
});

it('sets subscription_status = Trial and trial_ends_at when trial_days > 0', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'trial@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_trial',
    ]);

    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'sub_trial']);

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'trial_days' => 14,
    ]);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->trial_ends_at)->not->toBeNull();
    expect($billable->trial_ends_at->toDateString())
        ->toBe(BillingTime::nowUtc()->addDays(14)->toDateString());
});

it('clears trial_ends_at when a non-trial subscription is created on a billable that was on trial', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Trialing Co',
        'email' => 'trialing@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_trialing',
    ]);

    // Simulate a billable mid-trial — trial_ends_at is in the future and the
    // status is Trial (typical state when the dashboard banner is visible).
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(7),
    ])->save();

    Mollie::shouldReceive('send')->once()->andReturn((object) ['id' => 'sub_post_trial']);

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
    ]);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->trial_ends_at)->toBeNull();
    expect($billable->isOnBillingTrial())->toBeFalse();
});

it('uses default startDate (now + 1 interval) when no trial_days is given', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Normal Co',
        'email' => 'normal@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_normal',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')->once()->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_normal'];
    });

    $expectedStart = BillingTime::nowUtc()->addMonth()->toDateString();

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
    ]);

    $reflected = new ReflectionObject($captured);
    $startDateProp = $reflected->getProperty('startDate');
    $startDateProp->setAccessible(true);
    $startDate = $startDateProp->getValue($captured);

    expect((string) $startDate)->toBe($expectedStart);

    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->trial_ends_at)->toBeNull();
});
