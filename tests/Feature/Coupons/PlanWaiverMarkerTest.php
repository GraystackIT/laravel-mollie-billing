<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * The plan waiver: a plan that is free on a subscription Mollie charges for everything
 * else. Priced through the one recurring-discount slot — these tests pin what it adds.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'storage'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'storage'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.enterprise', [
        'name' => 'Enterprise',
        'tier' => 3,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => ['sso', 'storage'],
        'intervals' => [
            'monthly' => ['base_price_net' => 20000, 'seat_price_net' => 500, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]],
    ]);
    config()->set('mollie-billing-plans.addons.storage', [
        'name' => 'Storage',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 900], 'yearly' => ['price_net' => 9000]],
    ]);
});

/**
 * @param  array<int, string>  $addons
 * @return array{0: TestBillable, 1: Coupon}
 */
function waivedBillable(array $addons = [], int $seats = 2, array $granted = [], ?int $periods = null, int $days = 90): array
{
    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', $granted, $days);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gifted', 'email' => 'gift@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test', 'seat_count' => $seats],
        'mollie_customer_id' => 'cust_test',
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    app(PlanWaiver::class)->grant(
        $billable->fresh(),
        $coupon,
        'pro',
        'monthly',
        $granted,
        $periods === null ? BillingTime::nowUtc()->addDays($days) : null,
        $periods,
        PlanWaiver::ON_EXPIRY_END,
    );

    return [$billable->fresh(), $coupon];
}

it('waives the plan line and charges a paid addon and extra seats in full', function (): void {
    // Pro 50.00, 2 included seats; one extra seat 5.00; SSO 15.00 bought on top.
    [$billable] = waivedBillable(addons: ['sso'], seats: 3);

    $net = 5000 + 500 + 1500;

    expect(app(CouponService::class)->computeMarkerDiscount($billable, $net))->toBe(5000);
});

it('also waives an addon the gift itself included, while it stays active', function (): void {
    [$billable] = waivedBillable(addons: ['sso', 'storage'], granted: ['sso']);

    // Plan 50.00 + granted SSO 15.00 waived; Storage 9.00 is paid.
    expect(app(CouponService::class)->computeMarkerDiscount($billable, 5000 + 1500 + 900))->toBe(6500);

    // The granted addon is switched off: its share goes with it.
    $billable->forceFill(['active_addon_codes' => ['storage']])->save();

    expect(app(CouponService::class)->computeMarkerDiscount($billable->fresh(), 5000 + 900))->toBe(5000);
});

it('never waives more than the plan cost when it was given, on a more expensive plan', function (): void {
    [$billable] = waivedBillable();

    $discount = app(CouponService::class)->computeMarkerDiscount($billable, 20000, [
        'plan_code' => 'enterprise',
        'interval' => 'monthly',
        'addon_codes' => [],
        'seats' => 5,
    ]);

    // Enterprise costs 200.00; the gift was a 50.00 plan.
    expect($discount)->toBe(5000);
});

it('does not let a cheaper plan spill the waiver over onto addons', function (): void {
    [$billable] = waivedBillable(addons: ['sso']);

    $discount = app(CouponService::class)->computeMarkerDiscount($billable, 2000 + 1500, [
        'plan_code' => 'starter',
        'interval' => 'monthly',
        'addon_codes' => ['sso'],
        'seats' => 1,
    ]);

    // Starter 20.00 is waived; SSO 15.00 is still paid — not 35.00 off.
    expect($discount)->toBe(2000);
});

it('stops at its end date', function (): void {
    [$billable] = waivedBillable(days: 30);

    Carbon::setTestNow(BillingTime::nowUtc()->addDays(31));
    $discount = app(CouponService::class)->computeMarkerDiscount($billable->fresh(), 5000);
    Carbon::setTestNow();

    expect($discount)->toBe(0);
});

it('counts periods when it was given as a number of periods', function (): void {
    [$billable, $coupon] = waivedBillable(periods: 2);
    $service = app(CouponService::class);

    expect($service->redeemRecurringCouponForRenewal($billable, 5000, 1))->toBeInstanceOf(CouponRedemption::class);
    expect($service->computeMarkerDiscount($billable->fresh(), 5000))->toBe(5000);

    expect($service->redeemRecurringCouponForRenewal($billable->fresh(), 5000, 2))->toBeInstanceOf(CouponRedemption::class);

    // Two periods were promised and two were applied.
    expect($service->computeMarkerDiscount($billable->fresh(), 5000))->toBe(0);
    expect($service->markerExpired($billable->fresh()))->toBeTrue();
});

it('records a waived renewal without re-running the grant behind it', function (): void {
    [$billable, $coupon] = waivedBillable(days: 90);
    $before = $billable->fresh()->only(['subscription_source', 'subscription_plan_code', 'subscription_ends_at']);

    // A date-based waiver has no count: many renewals inside the window all apply.
    foreach ([1, 2, 3] as $invoiceId) {
        $row = app(CouponService::class)->redeemRecurringCouponForRenewal($billable->fresh(), 5000, $invoiceId);
        expect($row->discount_amount_net)->toBe(5000);
    }

    expect($billable->fresh()->only(['subscription_source', 'subscription_plan_code', 'subscription_ends_at']))->toEqual($before);
    expect((int) $coupon->fresh()->redemptions_count)->toBe(0);
    expect(app(CouponService::class)->computeMarkerDiscount($billable->fresh(), 5000))->toBe(5000);
});

it('can be extended by days and by periods', function (): void {
    [$billable] = waivedBillable(days: 30);
    $waiver = app(PlanWaiver::class);
    $end = Carbon::parse((string) $waiver->marker($billable)['valid_until']);

    $afterDays = $waiver->extendByDays($billable, 15);
    expect($afterDays->toDateString())->toBe($end->copy()->addDays(15)->toDateString());

    $afterPeriods = $waiver->extendByPeriods($billable->fresh(), 2);
    expect($afterPeriods->toDateString())->toBe($end->copy()->addDays(15 + 60)->toDateString());
});

it('says the subscription ends when the next charge is no longer covered and the gift ends', function (): void {
    [$billable] = waivedBillable(days: 40);
    $waiver = app(PlanWaiver::class);

    // Next charge in 30 days: still inside the 40. In 60 days: not any more.
    expect($waiver->endsSubscription($billable, BillingTime::nowUtc()->addDays(30)))->toBeFalse();
    expect($waiver->endsSubscription($billable, BillingTime::nowUtc()->addDays(60)))->toBeTrue();

    $waiver->setOnExpiry($billable, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    expect($waiver->endsSubscription($billable->fresh(), BillingTime::nowUtc()->addDays(60)))->toBeFalse();
});

it('refuses an ordinary recurring coupon while a waiver holds the slot', function (): void {
    [$billable] = waivedBillable();
    $service = app(CouponService::class);

    $service->create([
        'code' => 'REC20',
        'name' => '20%',
        'type' => CouponType::Recurring,
        'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    expect(fn () => $service->validate('REC20', $billable, ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidCouponException::class);
});
