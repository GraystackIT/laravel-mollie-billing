<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The customer's own "redeem a coupon" box on the dashboard. It accepts the types that
 * act at once (credits, trial / period extension, plan waiver); for each of them: is
 * what the customer is told what happened, and is a refusal a refusal?
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => 0, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
    ]);

    DashboardCouponMollie::$sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        DashboardCouponMollie::$sent[] = $request;

        return (object) ['id' => 'sub_dash'];
    });

    MollieBilling::authUsing(fn (): bool => true);
});

final class DashboardCouponMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static function lastStartDate(): ?string
    {
        $patches = array_values(array_filter(self::$sent, fn ($r) => $r instanceof UpdateSubscriptionRequest));

        if ($patches === []) {
            return null;
        }

        $p = (new ReflectionClass(end($patches)))->getProperty('startDate');
        $p->setAccessible(true);
        $date = $p->getValue(end($patches));

        return $date !== null ? (string) $date : null;
    }
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardCouponBillable(array $attributes): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Dash', 'email' => uniqid('dash-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'billing_street' => 'Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
    ], $attributes))->save();

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

function dashboardCouponMollieAttributes(): array
{
    return [
        'subscription_source' => SubscriptionSource::Mollie,
        'mollie_customer_id' => 'cst_dash',
        'mollie_mandate_id' => 'mdt_dash',
        'subscription_meta' => ['seat_count' => 2, 'mollie_subscription_id' => 'sub_dash'],
    ];
}

it('does not turn a free plan without an end date into one that expires', function (): void {
    // A period-extension code typed in by a customer on the free plan used to be
    // "redeemed": it wrote `now + N days` into an end date that did not exist, and the
    // free plan — which never expired — ran out N days later.
    $billable = dashboardCouponBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'free',
    ]);
    app(CouponService::class)->create(['code' => 'PLUS30', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 30]);

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'plus30')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', true);

    expect($billable->fresh()->getBillingSubscriptionEndsAt())->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(0);
});

it('extends a running trial when a period-extension code is redeemed during it', function (): void {
    $trialEnd = BillingTime::nowUtc()->addDays(5);
    $billable = dashboardCouponBillable(array_merge(dashboardCouponMollieAttributes(), [
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => $trialEnd,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(9),
    ]));
    app(CouponService::class)->create(['code' => 'PLUS10', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 10]);

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'PLUS10')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', false);

    $expected = Carbon::instance($trialEnd)->addDays(10);

    expect($billable->fresh()->getBillingTrialEndsAt()->toDateString())->toBe($expected->toDateString())
        ->and(DashboardCouponMollie::lastStartDate())->toBe($expected->toDateString())
        ->and(CouponRedemption::query()->first()->grant_days_added)->toBe(10);
});

it('gives a paying customer free plan periods, once per customer', function (): void {
    $billable = dashboardCouponBillable(array_merge(dashboardCouponMollieAttributes(), ['active_addon_codes' => ['sso']]));
    $coupons = app(CouponService::class);
    $coupons->create(['code' => 'FREE2', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 2]);

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'FREE2')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', false)
        ->assertSet('couponCode', '');

    $marker = app(PlanWaiver::class)->marker($billable->fresh());

    expect($marker['max_applications'])->toBe(2)
        ->and($coupons->computeMarkerDiscount($billable->fresh(), 6500))->toBe(5000)
        ->and(ChargeDeferral::until($billable->fresh()))->toBeNull();

    // The same code a second time: one per customer.
    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'FREE2')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', true)
        // The code stays in the field so a typo can be corrected.
        ->assertSet('couponCode', 'FREE2');

    expect(app(PlanWaiver::class)->marker($billable->fresh())['max_applications'])->toBe(2);
});

it('applies a recurring discount straight from the dashboard and says from when it counts', function (): void {
    $billable = dashboardCouponBillable(array_merge(dashboardCouponMollieAttributes(), ['active_addon_codes' => ['sso']]));
    app(CouponService::class)->create([
        'code' => 'REC20', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'discount_periods' => 3,
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'rec20')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', false)
        ->assertSet('couponMessage', __('billing::portal.coupon_recurring_applied_periods', [
            'code' => 'REC20',
            'from' => BillingTime::display($billable->nextBillingDate(), $billable)->translatedFormat('d. M Y'),
            'count' => 3,
        ]));

    expect($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon']['code'] ?? null)->toBe('REC20')
        ->and(CouponRedemption::query()->count())->toBe(1);
});

it('tells the customer what a single-payment code is for', function (): void {
    dashboardCouponBillable(dashboardCouponMollieAttributes());
    app(CouponService::class)->create([
        'code' => 'ONCE10', 'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'ONCE10')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', true)
        ->assertSet('couponMessage', __('billing::portal.coupon_single_payment_checkout'));

    expect(CouponRedemption::query()->count())->toBe(0);
});

it('refuses free plan periods while a recurring discount is running, without touching it', function (): void {
    $billable = dashboardCouponBillable(dashboardCouponMollieAttributes());
    $coupons = app(CouponService::class);
    $recurring = $coupons->create([
        'code' => 'REC30', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 30, 'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($recurring, $billable, 5000, 'monthly');
    $coupons->create(['code' => 'FREE3', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 3]);
    $before = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'FREE3')
        ->call('redeemCoupon')
        ->assertSet('couponMessageError', true);

    expect($billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'])->toBe($before)
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(DashboardCouponMollie::$sent)->toBe([]);
});
