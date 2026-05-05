<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'trial_days' => 14,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function billableForAllowList(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ])->save();

    return $billable;
}

it('rejects a Credits coupon when allowed_types only contains FirstPayment/Recurring (action flows)', function (): void {
    app(CouponService::class)->create([
        'code' => 'CREDS',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 100],
    ]);

    $billable = billableForAllowList();

    try {
        app(CouponService::class)->validate('CREDS', $billable->fresh(), [
            'planCode' => 'basic',
            'interval' => 'monthly',
            'orderAmountNet' => 1000,
            'allowed_types' => [CouponType::FirstPayment, CouponType::Recurring],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('rejects an AccessGrant coupon on one-time-order context (FirstPayment/Recurring only)', function (): void {
    app(CouponService::class)->create([
        'code' => 'AG30',
        'name' => 'Access Grant 30 days',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'basic',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
    ]);

    $billable = billableForAllowList();

    try {
        app(CouponService::class)->validate('AG30', $billable->fresh(), [
            'productCodes' => ['some-product'],
            'orderAmountNet' => 1000,
            'allowed_types' => [CouponType::FirstPayment, CouponType::Recurring],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('rejects a Credits coupon at checkout (allowed_types: FirstPayment/Recurring/TrialExtension/AccessGrant)', function (): void {
    app(CouponService::class)->create([
        'code' => 'CREDS',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 100],
    ]);

    try {
        app(CouponService::class)->validateWithoutBillable('CREDS', [
            'planCode' => 'basic',
            'interval' => 'monthly',
            'orderAmountNet' => 1000,
            'allowed_types' => [
                CouponType::FirstPayment,
                CouponType::Recurring,
                CouponType::TrialExtension,
                CouponType::AccessGrant,
            ],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('rejects an AccessGrant coupon on dashboard context (Credits/TrialExtension/PeriodExtension only)', function (): void {
    app(CouponService::class)->create([
        'code' => 'AG30',
        'name' => 'Access Grant 30 days',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'basic',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
    ]);

    $billable = billableForAllowList();

    try {
        app(CouponService::class)->validate('AG30', $billable->fresh(), [
            'planCode' => 'basic',
            'interval' => 'monthly',
            'orderAmountNet' => 1000,
            'allowed_types' => [CouponType::Credits, CouponType::TrialExtension, CouponType::PeriodExtension],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('accepts a Recurring coupon when allowed_types includes Recurring (action flows)', function (): void {
    app(CouponService::class)->create([
        'code' => 'REC10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = billableForAllowList();

    $coupon = app(CouponService::class)->validate('REC10', $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
        'allowed_types' => [CouponType::FirstPayment, CouponType::Recurring],
    ]);

    expect($coupon->code)->toBe('REC10');
});

it('accepts an empty allowed_types as no restriction (legacy callers)', function (): void {
    app(CouponService::class)->create([
        'code' => 'CREDS',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 100],
    ]);

    $billable = billableForAllowList();

    $coupon = app(CouponService::class)->validate('CREDS', $billable->fresh(), [
        'planCode' => 'basic',
        'interval' => 'monthly',
        'orderAmountNet' => 1000,
    ]);

    expect($coupon->code)->toBe('CREDS');
});
