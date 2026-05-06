<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
});

function localBasicBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Allow List',
        'email' => 'allow@x.test',
        'billing_country' => 'AT',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
    ])->save();

    return $billable;
}

it('rejects a SinglePayment coupon on plan change / seat sync / addon enable', function (): void {
    app(CouponService::class)->create([
        'code' => 'SP10',
        'name' => 'Single 10%',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
    ]);

    $billable = localBasicBillable();

    try {
        app(UpdateSubscription::class)->update($billable->fresh(), [
            'plan_code' => 'basic',
            'interval' => 'monthly',
            'coupon_codes' => ['SP10'],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('rejects a Credits coupon on plan change', function (): void {
    app(CouponService::class)->create([
        'code' => 'CREDS',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 100],
    ]);

    $billable = localBasicBillable();

    try {
        app(UpdateSubscription::class)->update($billable->fresh(), [
            'plan_code' => 'basic',
            'interval' => 'monthly',
            'coupon_codes' => ['CREDS'],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('rejects a TrialExtension coupon on plan change', function (): void {
    app(CouponService::class)->create([
        'code' => 'TRIAL14',
        'name' => 'Trial 14d',
        'type' => CouponType::TrialExtension,
        'trial_extension_days' => 14,
    ]);

    $billable = localBasicBillable();

    try {
        app(UpdateSubscription::class)->update($billable->fresh(), [
            'plan_code' => 'basic',
            'interval' => 'monthly',
            'coupon_codes' => ['TRIAL14'],
        ]);
        $this->fail('expected InvalidCouponException');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('type_not_allowed_in_context');
    }
});

it('accepts a Recurring coupon on plan change', function (): void {
    app(CouponService::class)->create([
        'code' => 'REC10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 6,
    ]);

    $billable = localBasicBillable();

    // Should not throw — Recurring is the only allowed type on plan change.
    app(UpdateSubscription::class)->update($billable->fresh(), [
        'plan_code' => 'basic',
        'interval' => 'monthly',
        'coupon_codes' => ['REC10'],
    ]);

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull()
        ->and($marker['code'])->toBe('REC10');
});
