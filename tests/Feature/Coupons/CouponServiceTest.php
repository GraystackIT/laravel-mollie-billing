<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\AccessGrantRequiresActiveSubscriptionException;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 14,
        'included_seats' => 1,
        'included_usages' => [],
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null],
        ],
    ]);
});

function freshBillable(): TestBillable
{
    return TestBillable::create(['name' => 'X', 'email' => 'x@y.test'])->refresh();
}

it('creates a first-payment coupon and validates and redeems it', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'welcome10',
        'name' => 'Welcome 10',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
    ]);

    expect($coupon->code)->toBe('WELCOME10');

    $billable = freshBillable();

    $resolved = $service->validate('WELCOME10', $billable, [
        'planCode' => 'pro',
        'interval' => 'monthly',
        'addonCodes' => [],
        'orderAmountNet' => 1000,
        'existingCouponIds' => [],
    ]);

    expect($resolved->id)->toBe($coupon->id);

    $redemption = $service->redeem($coupon, $billable, [
        'orderAmountNet' => 1000,
    ]);

    expect($redemption->discount_amount_net)->toBe(100);
    expect($coupon->refresh()->redemptions_count)->toBe(1);
});

it('rejects an expired coupon with the expired reason', function (): void {
    $service = app(CouponService::class);

    $coupon = Coupon::create([
        'code' => 'EXP',
        'name' => 'Expired',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'valid_until' => now()->subDay(),
    ]);

    $billable = freshBillable();

    try {
        $service->validate('EXP', $billable, []);
        expect(true)->toBeFalse('expected exception');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('expired');
    }
});

it('redeems a full access-grant coupon and activates a local subscription', function (): void {
    $billable = freshBillable();

    $mock = $this->mock(ActivateLocalSubscription::class);
    $mock->shouldReceive('handle')
        ->once()
        ->with(\Mockery::any(), 'pro', 'monthly', \Mockery::any(), 30)
        ->andReturnUsing(function ($b) {
            $b->forceFill([
                'subscription_source' => SubscriptionSource::Local,
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_plan_code' => 'pro',
                'subscription_interval' => SubscriptionInterval::Monthly,
                'subscription_period_starts_at' => now(),
                'subscription_ends_at' => now()->addDays(30),
            ])->save();
        });

    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'GRANT30',
        'name' => 'Grant 30',
        'type' => CouponType::AccessGrant,
        'grant_plan_code' => 'pro',
        'grant_interval' => 'monthly',
        'grant_duration_days' => 30,
        'grant_addon_codes' => [],
    ]);

    $redemption = $service->redeem($coupon, $billable, []);

    $billable->refresh();
    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);
    expect($redemption->grant_days_added)->toBe(30);
});

it('throws when an addon-only grant is applied to a billable without a local subscription', function (): void {
    $billable = freshBillable();

    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'ADDONX',
        'name' => 'Addon X',
        'type' => CouponType::AccessGrant,
        'grant_addon_codes' => ['print-gateway'],
    ]);

    expect(fn () => $service->validate('ADDONX', $billable, []))
        ->toThrow(AccessGrantRequiresActiveSubscriptionException::class);
});

it('enforces max_redemptions_per_billable on a second redemption', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'ONCE',
        'name' => 'Once',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 5,
        'max_redemptions_per_billable' => 1,
    ]);

    $billable = freshBillable();

    $service->redeem($coupon, $billable, ['orderAmountNet' => 1000]);

    try {
        $service->validate('ONCE', $billable, ['orderAmountNet' => 1000]);
        expect(true)->toBeFalse('expected exception');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('per_billable_limit_reached');
    }
});

it('resolves a coupon by auto-apply token and falls back to code', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'AUTO1',
        'name' => 'Auto1',
        'type' => CouponType::FirstPayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'generate_token' => true,
    ]);

    $token = (string) $coupon->refresh()->auto_apply_token;
    expect($token)->not->toBe('');

    expect($service->resolveByAutoApplyToken($token)?->id)->toBe($coupon->id);
    expect($service->resolveByAutoApplyToken('AUTO1')?->id)->toBe($coupon->id);
    expect($service->resolveByAutoApplyToken('does-not-exist'))->toBeNull();
});
