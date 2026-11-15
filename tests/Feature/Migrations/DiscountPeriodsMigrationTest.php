<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `discount_periods` takes over the meaning `max_redemptions_per_billable` had for a
 * recurring coupon ("how many charges are discounted"). The migration has to move that
 * number for coupons that already exist, so a discount that is running keeps exactly the
 * number of discounted charges it was sold with.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
});

function discountPeriodsMigration(): object
{
    return require __DIR__.'/../../../database/migrations/2026_11_16_000001_add_discount_periods_to_coupons_table.php';
}

/** The schema as it was before the migration, with coupons as an older release wrote them. */
function discountPeriodsLegacySchema(): void
{
    if (Schema::hasColumn('coupons', 'discount_periods')) {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('discount_periods'));
    }
}

function discountPeriodsLegacyCoupon(string $code, string $type, int $maxPerBillable, ?string $validUntil = null): int
{
    return (int) DB::table('coupons')->insertGetId([
        'code' => $code,
        'name' => $code,
        'type' => $type,
        'discount_type' => in_array($type, ['recurring', 'single_payment'], true) ? 'percentage' : null,
        'discount_value' => in_array($type, ['recurring', 'single_payment'], true) ? 20 : null,
        'max_redemptions_per_billable' => $maxPerBillable,
        'valid_until' => $validUntil,
        'active' => true,
        'stackable' => false,
        'redemptions_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('moves the period count of existing recurring coupons and leaves every other type alone', function (): void {
    discountPeriodsLegacySchema();

    $three = discountPeriodsLegacyCoupon('LEGACY3', 'recurring', 3);
    $one = discountPeriodsLegacyCoupon('LEGACY1', 'recurring', 1, '2030-01-01 00:00:00');
    $single = discountPeriodsLegacyCoupon('SINGLE2', 'single_payment', 2);

    discountPeriodsMigration()->up();

    expect(Schema::hasColumn('coupons', 'discount_periods'))->toBeTrue()
        ->and((array) DB::table('coupons')->where('id', $three)->first(['discount_periods', 'max_redemptions_per_billable']))
        ->toBe(['discount_periods' => 3, 'max_redemptions_per_billable' => 1])
        // "Valid until" plus the column default of 1 meant one discounted charge.
        ->and((array) DB::table('coupons')->where('id', $one)->first(['discount_periods', 'max_redemptions_per_billable']))
        ->toBe(['discount_periods' => 1, 'max_redemptions_per_billable' => 1])
        ->and((array) DB::table('coupons')->where('id', $single)->first(['discount_periods', 'max_redemptions_per_billable']))
        ->toBe(['discount_periods' => null, 'max_redemptions_per_billable' => 2]);
});

it('is idempotent: a second run does not touch coupons created under the new meaning', function (): void {
    discountPeriodsLegacySchema();
    discountPeriodsLegacyCoupon('LEGACY6', 'recurring', 6);
    discountPeriodsMigration()->up();

    // Created after the migration: until a date, no count, redeemable twice.
    $dated = app(CouponService::class)->create([
        'code' => 'DATED', 'type' => 'recurring', 'discount_type' => 'percentage', 'discount_value' => 10,
        'valid_until' => '2030-01-01 00:00:00', 'max_redemptions_per_billable' => 1,
    ]);
    DB::table('coupons')->where('id', $dated->id)->update(['max_redemptions_per_billable' => 2]);

    discountPeriodsMigration()->up();
    discountPeriodsMigration()->up();

    expect((array) DB::table('coupons')->where('id', $dated->id)->first(['discount_periods', 'max_redemptions_per_billable']))
        ->toBe(['discount_periods' => null, 'max_redemptions_per_billable' => 2])
        ->and((int) DB::table('coupons')->where('code', 'LEGACY6')->value('discount_periods'))->toBe(6);
});

it('keeps a running discount at exactly the charges it was sold with', function (int $alreadyDiscounted): void {
    discountPeriodsLegacySchema();
    $couponId = discountPeriodsLegacyCoupon('RUNNING3', 'recurring', 3);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Running', 'email' => uniqid('run-').'@x.test', 'billing_country' => 'AT']);
    $appliedAt = BillingTime::nowUtc()->subDays(30 * $alreadyDiscounted);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDay(),
        'mollie_customer_id' => 'cst_run',
        'mollie_mandate_id' => 'mdt_run',
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_run',
            'seat_count' => 1,
            // The marker an older release wrote: 3 × 30 + 1 days from the first charge.
            'active_recurring_coupon' => [
                'coupon_id' => $couponId,
                'code' => 'RUNNING3',
                'discount_type' => 'percentage',
                'discount_value' => 20,
                'valid_until' => $appliedAt->addDays(91)->toIso8601String(),
                'base_amount_net' => 5000,
                'first_applied_at' => $appliedAt->toIso8601String(),
            ],
        ],
    ])->save();

    // Rows as the old code wrote them: no marker of what is a grant and what a renewal.
    for ($i = 0; $i < $alreadyDiscounted; $i++) {
        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $couponId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'applied_at' => $appliedAt->addDays(30 * $i),
            'discount_amount_net' => 1000,
        ]);
    }

    discountPeriodsMigration()->up();

    $coupons = app(CouponService::class);
    $discounted = $alreadyDiscounted;

    // Renew until the discount stops.
    for ($i = 0; $i < 6; $i++) {
        if ($coupons->redeemRecurringCouponForRenewal($billable->fresh(), 5000, 0) === null) {
            break;
        }

        $discounted++;
    }

    expect($discounted)->toBe(3)
        ->and($coupons->computeMarkerDiscount($billable->fresh(), 5000))->toBe(0)
        ->and(CouponRedemption::query()->where('coupon_id', $couponId)->where('discount_amount_net', '>', 0)->count())->toBe(3)
        ->and(Coupon::query()->find($couponId)->max_redemptions_per_billable)->toBe(1);
})->with(['nothing used yet' => 0, 'one used' => 1, 'two used' => 2]);
