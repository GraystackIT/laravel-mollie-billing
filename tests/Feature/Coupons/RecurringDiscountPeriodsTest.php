<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Webhook\FirstPaymentCouponPricing;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * A recurring coupon says for how long it discounts in a field of its own
 * (`discount_periods`), and/or by a date (`valid_until`). `max_redemptions_per_billable`
 * means the same for every type: how often one customer may redeem the code.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
});

function discountPeriodsBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Periods', 'email' => uniqid('dp-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'mollie_customer_id' => 'cst_dp',
        'mollie_mandate_id' => 'mdt_dp',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_dp', 'seat_count' => 1],
    ])->save();

    return $billable->fresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function discountPeriodsCoupon(string $code, array $attributes): \GraystackIT\MollieBilling\Models\Coupon
{
    return app(CouponService::class)->create(array_merge([
        'code' => $code,
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
    ], $attributes));
}

/** Redeem, then renew month by month; returns how many charges were discounted. */
function discountPeriodsRun(TestBillable $billable, \GraystackIT\MollieBilling\Models\Coupon $coupon, int $renewals): int
{
    $coupons = app(CouponService::class);
    $start = BillingTime::nowUtc();

    $coupons->redeem($coupon, $billable, ['orderAmountNet' => 5000, 'interval' => 'monthly', 'discount_amount_net' => 1000]);
    $discounted = 1;

    try {
        for ($i = 1; $i <= $renewals; $i++) {
            Carbon::setTestNow($start->addMonthsNoOverflow($i));

            if ($coupons->redeemRecurringCouponForRenewal($billable->fresh(), 5000, 0) !== null) {
                $discounted++;
            }
        }
    } finally {
        Carbon::setTestNow();
    }

    return $discounted;
}

it('discounts exactly discount_periods charges, and lets the customer redeem the code once', function (): void {
    $billable = discountPeriodsBillable();
    $coupon = discountPeriodsCoupon('TWELVE', ['discount_periods' => 12]);

    expect($coupon->fresh()->discount_periods)->toBe(12)
        ->and($coupon->fresh()->max_redemptions_per_billable)->toBe(1);

    // Twelve discounted charges although the customer may redeem only once …
    expect(discountPeriodsRun($billable, $coupon, 15))->toBe(12)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(12)
        ->and($coupon->fresh()->redemptions_count)->toBe(1);

    // … and not a second time, during the discount or after it.
    $coupons = app(CouponService::class);
    $coupons->clearActiveRecurringCouponMarker($billable->fresh());

    expect(fn () => $coupons->validate('TWELVE', $billable->fresh(), ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]))
        ->toThrow(InvalidCouponException::class, 'per_billable_limit_reached');
    expect(fn () => $coupons->redeem($coupon->fresh(), $billable->fresh(), ['orderAmountNet' => 5000, 'interval' => 'monthly']))
        ->toThrow(InvalidCouponException::class, 'per_billable_limit_reached');
});

it('discounts until the date, without a count, when only valid_until is set', function (): void {
    $billable = discountPeriodsBillable();
    $coupon = discountPeriodsCoupon('UNTIL', ['valid_until' => BillingTime::nowUtc()->addDays(200)]);

    expect($coupon->discount_periods)->toBeNull();

    // Redeemed today, renewals after 1 … 9 months: the ones before day 200 are discounted.
    expect(discountPeriodsRun($billable, $coupon, 9))->toBe(7);
});

it('ends at whichever comes first when both are set', function (int $periods, int $days, int $expected): void {
    $billable = discountPeriodsBillable();
    $coupon = discountPeriodsCoupon('BOTH', ['discount_periods' => $periods, 'valid_until' => BillingTime::nowUtc()->addDays($days)]);

    expect(discountPeriodsRun($billable, $coupon, 9))->toBe($expected);
})->with([
    'the count ends it' => [3, 300, 3],
    'the date ends it' => [8, 100, 4],
]);

it('refuses a recurring coupon with neither a period count nor a date', function (): void {
    expect(fn () => discountPeriodsCoupon('NOLIMIT', []))
        ->toThrow(InvalidArgumentException::class, 'Recurring coupons need a limit');

    // "Redeemable twice" is not a lifetime.
    $coupon = discountPeriodsCoupon('OK', ['discount_periods' => 2]);
    expect(fn () => app(CouponService::class)->update($coupon, ['discount_periods' => null]))
        ->toThrow(InvalidArgumentException::class, 'Recurring coupons need a limit');
});

it('derives the marker lifetime from discount_periods in the interval it runs at', function (string $interval, string $from, string $expected): void {
    $coupon = discountPeriodsCoupon('LIFE'.strtoupper($interval), ['discount_periods' => 2, 'max_redemptions_per_billable' => 5]);

    // The day before the third charge: two billing periods — calendar months or years on
    // the day the first one started, shortened where a month does not have it.
    expect(app(CouponService::class)->recurringMarkerValidUntil($coupon, $interval, Carbon::parse($from, 'UTC'))->toIso8601String())
        ->toBe($expected);
})->with([
    'monthly from the 1st' => ['monthly', '2027-01-01 00:00:00', '2027-02-28T00:00:00+00:00'],
    'yearly from the 1st' => ['yearly', '2027-01-01 00:00:00', '2028-12-31T00:00:00+00:00'],
    // Charges 31 Jan, 28 Feb, 31 Mar: the window ends on 30 March, not on 2 April.
    'monthly from the 31st' => ['monthly', '2027-01-31 10:00:00', '2027-03-30T10:00:00+00:00'],
    // Charges 29 Feb 2028, 28 Feb 2029, 28 Feb 2030.
    'yearly from 29 February' => ['yearly', '2028-02-29 10:00:00', '2030-02-27T10:00:00+00:00'],
]);

it('lets a customer redeem a recurring coupon again when the per-customer limit allows it', function (): void {
    $billable = discountPeriodsBillable();
    $coupon = discountPeriodsCoupon('AGAIN', ['discount_periods' => 2, 'max_redemptions_per_billable' => 2]);
    $coupons = app(CouponService::class);

    expect(discountPeriodsRun($billable, $coupon, 3))->toBe(2);
    $coupons->clearActiveRecurringCouponMarker($billable->fresh());

    // Second redemption, months later: two more discounted charges, not zero.
    Carbon::setTestNow(BillingTime::nowUtc()->addMonthsNoOverflow(6));

    try {
        $coupons->validate('AGAIN', $billable->fresh(), ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]);
        $coupons->redeem($coupon->fresh(), $billable->fresh(), ['orderAmountNet' => 5000, 'interval' => 'monthly', 'discount_amount_net' => 1000]);

        expect($coupons->computeMarkerDiscount($billable->fresh(), 5000))->toBe(1000);

        Carbon::setTestNow(BillingTime::nowUtc()->addMonthNoOverflow());
        expect($coupons->redeemRecurringCouponForRenewal($billable->fresh(), 5000, 0))->not->toBeNull()
            ->and($coupons->computeMarkerDiscount($billable->fresh(), 5000))->toBe(0);
    } finally {
        Carbon::setTestNow();
    }

    // And a third time is refused.
    $coupons->clearActiveRecurringCouponMarker($billable->fresh());
    expect(fn () => $coupons->validate('AGAIN', $billable->fresh(), ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]))
        ->toThrow(InvalidCouponException::class, 'per_billable_limit_reached');
});

// ── Coupons created the way they used to be ─────────────────────────────────

it('reads a per-customer limit passed without discount_periods as the period count it used to be', function (): void {
    // What every app that creates coupons in code does today, and what the admin form
    // of the previous release submitted.
    $three = discountPeriodsCoupon('OLD3', ['max_redemptions_per_billable' => 3]);
    $one = discountPeriodsCoupon('OLD1', ['max_redemptions_per_billable' => 1]);

    expect([$three->discount_periods, $three->max_redemptions_per_billable])->toBe([3, 1])
        ->and([$one->discount_periods, $one->max_redemptions_per_billable])->toBe([1, 1]);

    expect(discountPeriodsRun(discountPeriodsBillable(), $three, 6))->toBe(3);
});

it('keeps both numbers apart as soon as discount_periods is given', function (): void {
    $coupon = discountPeriodsCoupon('NEW', ['discount_periods' => 6, 'max_redemptions_per_billable' => 2]);

    expect([$coupon->discount_periods, $coupon->max_redemptions_per_billable])->toBe([6, 2]);
});

it('does not turn the default per-customer limit into a count when a date is the limit', function (): void {
    $coupon = discountPeriodsCoupon('DATEONLY', [
        'valid_until' => BillingTime::nowUtc()->addYear(),
        'max_redemptions_per_billable' => 1,
    ]);

    expect($coupon->discount_periods)->toBeNull();
});

// ── What the Mollie subscription is created at ──────────────────────────────

it('creates the subscription at the discounted amount only while a discounted charge is left', function (int $periods, bool $trial, int $expectedRecurring): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'First', 'email' => uniqid('fp-').'@x.test', 'billing_country' => 'AT']);
    discountPeriodsCoupon('FIRST', ['discount_periods' => $periods, 'max_redemptions_per_billable' => 4]);

    $priced = app(FirstPaymentCouponPricing::class)->price($billable, 'FIRST', 'pro', 'monthly', [], 0, trialStarting: $trial);

    // The first payment is the first application: with one period nothing is left for
    // Mollie's recurring amount — however often the customer may redeem the code.
    expect($priced['discount_net'])->toBe(1000)
        ->and($priced['recurring_discount_net'])->toBe($expectedRecurring);
})->with([
    'one period, paid signup' => [1, false, 0],
    'two periods, paid signup' => [2, false, 1000],
    'one period, trial signup' => [1, true, 1000],
]);
