<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Support\BillingTime;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.basic', [
        'name' => 'Basic',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.premium', [
        'name' => 'Premium',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 30000, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);
});

function makeBillableWithBasicPlan(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Plan Switcher',
        'email' => 'switch@x.test',
        'billing_country' => 'AT',
    ]);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(15),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    return $billable;
}

it('SinglePayment coupon is rejected on plan change — surfaces as a preview warning, no discount applied', function (): void {
    // single_payment is intentionally not allowed on plan-change / seat-sync /
    // addon-enable paths anymore. The legitimate use case for "first charge free"
    // lives in Subscription Checkout (Mandate-Only) and One-Time-Order purchases.
    app(CouponService::class)->create([
        'code' => 'PRORATA20',
        'name' => 'First Payment 20%',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
    ]);

    $billable = makeBillableWithBasicPlan();

    $preview = app(PreviewService::class)->previewUpdate($billable->fresh(), new SubscriptionUpdateRequest(
        planCode: 'premium',
        interval: 'monthly',
        couponCodes: ['PRORATA20'],
    ));

    // No discount on either side — the coupon was rejected at validate-time.
    expect((int) ($preview['couponDiscountNet'] ?? 0))->toBe(0)
        ->and((int) ($preview['newPriceNet'] ?? 0))->toBe(3000);

    $couponLines = array_values(array_filter(
        (array) ($preview['lineItems'] ?? []),
        fn ($l) => ($l['kind'] ?? null) === 'coupon',
    ));
    expect($couponLines)->toBeEmpty();

    // The PreviewService catches the InvalidCouponException and reports it as a warning.
    $warnings = (array) ($preview['warnings'] ?? []);
    expect($warnings)->not->toBeEmpty()
        ->and(implode(' ', $warnings))->toContain('PRORATA20');
});

it('Recurring coupon reduces the recurring price and shows up as a recurring line item', function (): void {
    app(CouponService::class)->create([
        'code' => 'REC10',
        'name' => 'Recurring 10%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = makeBillableWithBasicPlan();

    $preview = app(PreviewService::class)->previewUpdate($billable->fresh(), new SubscriptionUpdateRequest(
        planCode: 'premium',
        interval: 'monthly',
        couponCodes: ['REC10'],
    ));

    // Recurring price reduced by 10%: 3000 - 10% = 300.
    expect((int) ($preview['couponDiscountNet'] ?? 0))->toBe(300)
        ->and((int) ($preview['newPriceNet'] ?? 0))->toBe(3000);

    // Recurring line items include the coupon-discount line.
    $couponLines = array_values(array_filter(
        (array) ($preview['lineItems'] ?? []),
        fn ($l) => ($l['kind'] ?? null) === 'coupon',
    ));
    expect($couponLines)->toHaveCount(1)
        ->and((int) $couponLines[0]['total_net'])->toBe(-300);
});

it('AccessGrant/Credits/TrialExtension have no effect on recurring or prorata', function (): void {
    app(CouponService::class)->create([
        'code' => 'CREDS',
        'name' => 'Credits',
        'type' => CouponType::Credits,
        'credits_payload' => ['tokens' => 100],
    ]);

    $billable = makeBillableWithBasicPlan();

    $preview = app(PreviewService::class)->previewUpdate($billable->fresh(), new SubscriptionUpdateRequest(
        planCode: 'premium',
        interval: 'monthly',
        couponCodes: ['CREDS'],
    ));

    expect((int) ($preview['couponDiscountNet'] ?? 0))->toBe(0);
    $couponLines = array_values(array_filter(
        (array) ($preview['lineItems'] ?? []),
        fn ($l) => ($l['kind'] ?? null) === 'coupon',
    ));
    expect($couponLines)->toBeEmpty();
});
