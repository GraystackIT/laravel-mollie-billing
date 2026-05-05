<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
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

it('configures the Mollie subscription with the FULL recurring amount, not the discounted first-payment amount', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Test',
        'email' => 'test@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_test',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->andReturnUsing(function ($request) use (&$captured) {
            $captured = $request;
            return (object) ['id' => 'sub_test'];
        });

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        // No `recurring_discount_net` → MUST charge the full price for following periods,
        // even if the first payment was discounted by a FirstPayment coupon.
    ]);

    expect($captured)->toBeInstanceOf(CreateSubscriptionRequest::class);

    // The Mollie amount must reflect 1000 cents net (= 1200 gross at 20% VAT).
    $reflected = new ReflectionObject($captured);
    $amountProp = $reflected->getProperty('amount');
    $amountProp->setAccessible(true);
    $money = $amountProp->getValue($captured);

    expect($money->value)->toBe('12.00');
});

it('passes recurring_discount_net through to the Mollie subscription amount when set', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Test',
        'email' => 'test@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cust_test',
    ]);

    $captured = null;
    Mollie::shouldReceive('send')
        ->once()
        ->andReturnUsing(function ($request) use (&$captured) {
            $captured = $request;
            return (object) ['id' => 'sub_test'];
        });

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'recurring_discount_net' => 100, // 10% off on a 1000 cent net plan
    ]);

    $reflected = new ReflectionObject($captured);
    $amountProp = $reflected->getProperty('amount');
    $amountProp->setAccessible(true);
    $money = $amountProp->getValue($captured);

    // 1000 - 100 = 900 net; +20% VAT = 1080 gross.
    expect($money->value)->toBe('10.80');
});
