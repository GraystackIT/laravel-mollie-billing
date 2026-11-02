<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function capsBillable(string $email = 'caps@x.test'): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Caps Co', 'email' => $email]);

    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('enforces the per-billable cap inside redeem, not only in validate', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'CREDITS10',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['emails' => 100],
        'max_redemptions_per_billable' => 1,
        'active' => true,
    ]);

    $service->redeem($coupon, $billable, []);

    // A double-click reaches redeem() twice without validate() in between; the cap has to
    // hold under the coupon lock or the wallet is credited twice.
    expect(fn () => $service->redeem($coupon->refresh(), $billable, []))
        ->toThrow(InvalidCouponException::class);

    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
    expect($billable->refresh()->getWallet('emails')?->balanceInt)->toBe(100);
});

it('frees the per-billable slot again once a grant is revoked', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'CREDITSREV',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['emails' => 50],
        'max_redemptions_per_billable' => 1,
        'active' => true,
    ]);

    $redemption = $service->redeem($coupon, $billable, []);
    $redemption->forceFill(['revoked_at' => BillingTime::nowUtc()])->save();

    // Counts live redemptions only — mirrors revokeGrant()'s counter decrement.
    expect(fn () => $service->redeem($coupon->refresh(), $billable, []))->not->toThrow(InvalidCouponException::class);
});

it('refuses the renewal bypass for a coupon that is not recurring', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'ONESHOT',
        'name' => 'One shot',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 500,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 1,
        'active' => true,
    ]);

    $service->redeem($coupon, $billable, ['discount_amount_net' => 500]);
    expect($coupon->refresh()->redemptions_count)->toBe(1);

    // The renewal exemption exists because for a Recurring coupon
    // `max_redemptions_per_billable` counts billing periods, not grants. A
    // SinglePayment coupon has no such reading, so the flag must not buy it a second
    // redemption past an exhausted campaign — `redeem()` is public API, and the flag
    // arrives in a caller-supplied array.
    expect(fn () => $service->redeem($coupon->refresh(), $billable, [
        'renewal' => true,
        'discount_amount_net' => 500,
    ]))->toThrow(InvalidCouponException::class);

    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('refuses the renewal bypass for a billable that holds no grant of the coupon', function (): void {
    $service = app(CouponService::class);
    $customer = capsBillable();
    $stranger = capsBillable('stranger@x.test');

    $coupon = $service->create([
        'code' => 'RECURCAP',
        'name' => 'Recurring capped',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 12,
        'active' => true,
    ]);

    // The only campaign slot goes to the first customer.
    $service->redeem($coupon, $customer, ['orderAmountNet' => 2900]);
    expect($coupon->refresh()->redemptions_count)->toBe(1);

    // The renewal exemption re-applies a grant that already exists. Without checking for
    // that grant, a caller-supplied `renewal` flag let a billable that never had one skip
    // both caps — and, with `skip_marker` omitted, write itself a fresh discount marker.
    expect(fn () => $service->redeem($coupon->refresh(), $stranger, [
        'renewal' => true,
        'orderAmountNet' => 2900,
    ]))->toThrow(InvalidCouponException::class);

    expect($stranger->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null)->toBeNull()
        ->and(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);
});

it('does not rewrite the recurring marker on a renewal', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'RECURKEEP',
        'name' => 'Recurring keep',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 12,
        'active' => true,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 2900]);
    $before = $billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    // A renewal must not reset what the grant was locked to (base amount, lifetime).
    $service->redeem($coupon->refresh(), $billable->refresh(), [
        'renewal' => true,
        'orderAmountNet' => 9900,
    ]);

    expect($billable->refresh()->getBillingSubscriptionMeta()['active_recurring_coupon'])->toBe($before);
});

it('does not let recurring renewals consume the global new-customer cap', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'RECUR50',
        'name' => 'Recurring 50%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions' => 2,
        'max_redemptions_per_billable' => 12,
        'active' => true,
    ]);

    // The initial grant consumes one of the two campaign slots.
    $service->redeem($coupon, $billable, ['orderAmountNet' => 2900]);
    expect($coupon->refresh()->redemptions_count)->toBe(1);

    // Five monthly renewals of that same grant.
    for ($i = 0; $i < 5; $i++) {
        $redemption = $service->redeemRecurringCouponForRenewal($billable->refresh(), 2900, 0);
        expect($redemption)->not->toBeNull();
    }

    // The cap sizes the campaign in customers, so renewals must not eat it — otherwise a
    // 50-redemption coupon is exhausted by 20 subscribers within months.
    expect($coupon->refresh()->redemptions_count)->toBe(1);
    // Every application still leaves an audit row: 1 grant + 5 renewals.
    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(6);

    // And the second real customer still gets in.
    $second = capsBillable('caps2@x.test');
    $service->redeem($coupon->refresh(), $second, ['orderAmountNet' => 2900]);
    expect($coupon->refresh()->redemptions_count)->toBe(2);

    // Now the campaign is genuinely exhausted for new customers.
    $third = capsBillable('caps3@x.test');
    expect(fn () => $service->redeem($coupon->refresh(), $third, ['orderAmountNet' => 2900]))
        ->toThrow(InvalidCouponException::class);
});

it('keeps writing renewal audit rows after the global cap is reached', function (): void {
    $service = app(CouponService::class);
    $billable = capsBillable();

    $coupon = $service->create([
        'code' => 'RECUR1',
        'name' => 'Recurring',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'max_redemptions' => 1,
        'max_redemptions_per_billable' => 6,
        'active' => true,
    ]);

    $service->redeem($coupon, $billable, ['orderAmountNet' => 2900]);
    expect($coupon->refresh()->redemptions_count)->toBe(1);

    // The discount keeps applying (computeMarkerDiscount only checks active + valid_until),
    // so the redemption row must keep being written instead of throwing `globally_exhausted`
    // and letting the counter drift away from the actual rows.
    expect($service->computeMarkerDiscount($billable->refresh(), 2900))->toBe(725);

    $renewal = $service->redeemRecurringCouponForRenewal($billable->refresh(), 2900, 0);

    expect($renewal)->not->toBeNull();
    expect($renewal->discount_amount_net)->toBe(725);
    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(2);
});

it('rejects raising a percentage discount above 100% on update', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'PCT50',
        'name' => 'Half off',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 3,
        'active' => true,
    ]);

    // create() guards this; update() used to skip every type/discount rule, and the value
    // then flowed uncapped into the recurring marker.
    expect(fn () => $service->update($coupon, ['discount_value' => 150]))
        ->toThrow(\InvalidArgumentException::class);

    expect($coupon->refresh()->discount_value)->toBe(50);
});

it('still allows ordinary coupon updates', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'PCT20',
        'name' => 'Twenty',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
        'active' => true,
    ]);

    $updated = $service->update($coupon, ['name' => 'Twenty percent', 'discount_value' => 30]);

    expect($updated->name)->toBe('Twenty percent');
    expect($updated->discount_value)->toBe(30);
});

it('does not break updating a credits coupon whose payload is only in the database', function (): void {
    $service = app(CouponService::class);

    $coupon = $service->create([
        'code' => 'CREDUP',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['emails' => 10],
        'active' => true,
    ]);

    // Validation runs against casted attributes, so the JSON column is seen as an array.
    $updated = $service->update(Coupon::findOrFail($coupon->id), ['name' => 'Credits v2']);

    expect($updated->name)->toBe('Credits v2');
});
