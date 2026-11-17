<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * Every coupon type at every place a customer can type a code: accepted where it has a
 * meaning, refused with "this coupon type cannot be redeemed here" where it has none.
 *
 * Driven through the Livewire components themselves — each of them carries its own
 * `allowed_types` list, and that list is what this pins. (The dashboard takes every type
 * and answers per situation: see tests/Feature/Portal/RedeemCouponFromPortalTest.php.)
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'trial_days' => 14, 'included_usages' => ['tokens' => 100]]],
        ],
        'max' => [
            'name' => 'Max', 'tier' => 3, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 9000, 'seat_price_net' => 500, 'included_usages' => ['tokens' => 100]]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
    ]);
    config()->set('mollie-billing-plans.products', [
        'token-pack' => ['name' => 'Token pack', 'price_net' => 1000, 'usage_type' => 'tokens', 'quantity' => 500],
    ]);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_entry']);
    MollieBilling::authUsing(fn (): bool => true);

    $coupons = app(CouponService::class);
    $discount = ['discount_type' => DiscountType::Percentage, 'discount_value' => 10];

    $coupons->create(['code' => 'T-SINGLE', 'type' => CouponType::SinglePayment] + $discount);
    $coupons->create(['code' => 'T-RECURRING', 'type' => CouponType::Recurring, 'discount_periods' => 3] + $discount);
    $coupons->create(['code' => 'T-CREDITS', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 10]]);
    $coupons->create(['code' => 'T-TRIAL', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 7]);
    $coupons->create(['code' => 'T-GRANT', 'type' => CouponType::AccessGrant, 'grant_plan_code' => 'pro', 'grant_interval' => 'monthly', 'grant_duration_days' => 30]);
    $coupons->create(['code' => 'T-PERIOD', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 14]);
    $coupons->create(['code' => 'T-WAIVER', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 2]);
});

function entryMatrixSubscriber(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Entry', 'email' => uniqid('en-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'billing_street' => 'Street 1', 'billing_postal_code' => '1010', 'billing_city' => 'Vienna',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_entry', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_entry',
        'mollie_mandate_id' => 'mdt_entry',
    ])->save();

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** The error a place shows after the code was applied there, or null when it took it. */
function entryMatrixApply(string $place, string $code): ?string
{
    if ($place === 'checkout') {
        /** @var TestBillable $fresh */
        $fresh = TestBillable::create(['name' => 'New', 'email' => uniqid('new-').'@x.test', 'billing_country' => 'AT']);
        MollieBilling::resolveBillableUsing(fn () => $fresh->fresh());

        return Livewire::test('mollie-billing::checkout')
            // Pro starts with a trial, which a trial extension needs and a first-payment
            // discount cannot be combined with (`coupon_not_with_trial`): that one is
            // tried on the plan without a trial.
            ->set('plan_code', $code === 'T-SINGLE' ? 'max' : 'pro')
            ->set('interval', 'monthly')
            ->set('coupon_input', $code)
            ->call('applyCoupon')
            ->get('couponError');
    }

    entryMatrixSubscriber();

    return match ($place) {
        'plan change' => Livewire::test('mollie-billing::plan-change')
            ->set('selectedPlan', 'max')
            ->set('couponInput', $code)
            ->call('applyCoupon')
            ->get('couponError'),
        'seats' => Livewire::test('mollie-billing::seats')
            ->set('seatCount', 4)
            ->set('couponInput', $code)
            ->call('applyCoupon')
            ->get('couponError'),
        'addon' => Livewire::test('mollie-billing::addons')
            ->set('couponInputs.sso', $code)
            ->call('applyCoupon', 'sso')
            ->get('couponErrors')['sso'] ?? null,
        'product' => Livewire::test('mollie-billing::products')
            ->set('couponInputs.token-pack', $code)
            ->call('applyCoupon', 'token-pack')
            ->get('couponErrors')['token-pack'] ?? null,
    };
}

it('takes or refuses each coupon type at each place a code can be typed', function (string $place, string $type, bool $accepted): void {
    $error = entryMatrixApply($place, 'T-'.strtoupper($type));

    if ($accepted) {
        expect($error)->toBeNull();
    } else {
        expect($error)->toBe(__('billing::checkout.coupon_type_not_allowed_in_context'));
    }
})->with(function (): array {
    $accepts = [
        'checkout' => ['single', 'recurring', 'trial', 'grant'],
        'plan change' => ['recurring'],
        'seats' => ['recurring'],
        'addon' => ['recurring'],
        'product' => ['single'],
    ];

    $cases = [];

    foreach ($accepts as $place => $taken) {
        foreach (['single', 'recurring', 'credits', 'trial', 'grant', 'period', 'waiver'] as $type) {
            $cases["{$type} at {$place}"] = [$place, $type, in_array($type, $taken, true)];
        }
    }

    return $cases;
});

it('lists every coupon type in the matrix', function (): void {
    // A new CouponType has to be given a row above before this passes again.
    expect(count(CouponType::cases()))->toBe(7);
});
