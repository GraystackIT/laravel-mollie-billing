<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\SubscriptionAmount;
use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * What a plan waiver takes off, for every configuration a tenant can move to while it
 * runs. Two bounds, always both: what was waived when the gift was given (an upgrade
 * does not enlarge it) and what the waived lines cost in the configuration being priced
 * (a cheaper plan does not spill the difference onto paid extras).
 *
 *   Starter 20.00 · Pro 50.00 · Enterprise 90.00 — 2 seats included, 5.00 per extra seat
 *   SSO 15.00 flat · CRM 10.00 + 2.00 per seat beyond its own 2 included
 *   Yearly = 10 × monthly
 */

beforeEach(function (): void {
    foreach (['starter' => 2000, 'pro' => 5000, 'enterprise' => 9000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => 1,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['sso', 'crm'],
            'intervals' => [
                'monthly' => ['base_price_net' => $price, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => $price * 10, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ]);
    }

    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]],
    ]);
    config()->set('mollie-billing-plans.addons.crm', [
        'name' => 'CRM',
        'feature_keys' => [],
        'included_seats' => 2,
        'intervals' => [
            'monthly' => ['price_net' => 1000, 'seat_price_net' => 200],
            'yearly' => ['price_net' => 10000, 'seat_price_net' => 2000],
        ],
    ]);
});

/**
 * A tenant on a gift of Pro (optionally with addons the gift itself included) who has
 * moved to a Mollie subscription.
 *
 * @param  list<string>  $granted
 * @param  list<string>  $active
 */
function waiverCeilingBillable(array $granted, array $active, int $seats = 2): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Ceiling', 'email' => uniqid('ceil-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'active_addon_codes' => $active,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_ceiling', 'seat_count' => $seats],
        'mollie_customer_id' => 'cust_ceiling',
        'mollie_mandate_id' => 'mdt_ceiling',
    ])->save();

    $coupon = app(CouponService::class)->accessGrantCoupon(
        'GIFT-CEIL-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', $granted, 90,
    );

    app(PlanWaiver::class)->grant(
        $billable->fresh(), $coupon, 'pro', 'monthly', $granted, BillingTime::nowUtc()->addDays(90), null, PlanWaiver::ON_EXPIRY_END,
    );

    return $billable->fresh();
}

/**
 * [discount, what the tenant pays] for a target configuration.
 *
 * @param  list<string>  $addons
 * @return array{0: int, 1: int}
 */
function waiverCeilingPrice(TestBillable $billable, string $plan, string $interval, array $addons, int $seats): array
{
    $net = SubscriptionAmount::net(app(SubscriptionCatalogInterface::class), $billable, $plan, $interval, $seats, $addons);
    $discount = app(CouponService::class)->computeMarkerDiscount($billable, $net, [
        'plan_code' => $plan,
        'interval' => $interval,
        'addon_codes' => $addons,
        'seats' => $seats,
    ]);

    return [$discount, $net - $discount];
}

it('waives the plan line and nothing else, in every configuration', function (
    string $plan,
    array $addons,
    int $seats,
    int $expectedDiscount,
    int $expectedCharge,
): void {
    // The gift: Pro, no addons of its own. SSO, if present, is the tenant's purchase.
    $billable = waiverCeilingBillable(granted: [], active: ['sso']);

    expect(waiverCeilingPrice($billable, $plan, 'monthly', $addons, $seats))
        ->toBe([$expectedDiscount, $expectedCharge]);
})->with([
    'as given: Pro + bought SSO' => ['pro', ['sso'], 2, 5000, 1500],
    'bought addon removed: plan only, fully covered' => ['pro', [], 2, 5000, 0],
    'three extra seats are paid' => ['pro', ['sso'], 5, 5000, 3000],
    'second bought addon is paid' => ['pro', ['sso', 'crm'], 2, 5000, 2500],
    'upgrade: the gift is not enlarged' => ['enterprise', ['sso'], 2, 5000, 5500],
    'upgrade with seats' => ['enterprise', [], 4, 5000, 5000],
    'downgrade: nothing spills onto the addon' => ['starter', ['sso'], 2, 2000, 1500],
    'downgrade with seats: nothing spills onto the seats' => ['starter', [], 6, 2000, 2000],
]);

it('waives a granted addon while it is active and stops when it is switched off', function (
    string $plan,
    array $addons,
    int $seats,
    int $expectedDiscount,
    int $expectedCharge,
): void {
    // The gift: Pro + SSO. CRM is bought by the tenant.
    $billable = waiverCeilingBillable(granted: ['sso'], active: ['sso', 'crm']);

    expect(waiverCeilingPrice($billable, $plan, 'monthly', $addons, $seats))
        ->toBe([$expectedDiscount, $expectedCharge]);
})->with([
    'as given, plus bought CRM' => ['pro', ['sso', 'crm'], 2, 6500, 1000],
    'granted addon switched off: only the plan is waived' => ['pro', ['crm'], 2, 5000, 1000],
    'granted addon switched off, nothing bought' => ['pro', [], 2, 5000, 0],
    'bought addon removed: fully covered' => ['pro', ['sso'], 2, 6500, 0],
    'downgrade keeps the granted addon free' => ['starter', ['sso', 'crm'], 2, 3500, 1000],
    'extra seats are paid' => ['pro', ['sso'], 4, 6500, 1000],
]);

it('covers a granted per-seat addon up to what it cost when the gift was given', function (
    int $seats,
    int $expectedDiscount,
    int $expectedCharge,
): void {
    // The gift: Pro + CRM at 4 seats → 50.00 + 10.00 + 2 charged addon seats × 2.00.
    $billable = waiverCeilingBillable(granted: ['crm'], active: ['crm'], seats: 4);

    expect((int) app(PlanWaiver::class)->marker($billable)['base_amount_net'])->toBe(6400)
        ->and(waiverCeilingPrice($billable, 'pro', 'monthly', ['crm'], $seats))
        ->toBe([$expectedDiscount, $expectedCharge]);
})->with([
    // 2 extra plan seats (10.00) are the tenant's.
    'as given' => [4, 6400, 1000],
    // Fewer seats: the addon costs less, and so does the waiver.
    'down to the included seats' => [2, 6000, 0],
    // More seats: 4 plan seats (20.00) + 2 more addon seats (4.00) beyond the gift.
    'two seats more' => [6, 6400, 2400],
]);

it('keeps the monthly amount as the ceiling after a change of interval', function (): void {
    // Pinned as it is, and reported as an open product question: a gift is locked to
    // the amount waived when it was given, so on a yearly charge it takes off one
    // month's plan price — the tenant pays 450.00 of a 500.00 year although the plan is
    // free until the gift ends.
    $billable = waiverCeilingBillable(granted: [], active: []);

    expect(waiverCeilingPrice($billable, 'pro', 'yearly', [], 2))->toBe([5000, 45000]);
});

it('never takes off more than the charge, and nothing from a charge of zero', function (): void {
    $billable = waiverCeilingBillable(granted: [], active: []);
    $coupons = app(CouponService::class);

    expect($coupons->computeMarkerDiscount($billable, 3000))->toBe(3000)
        ->and($coupons->computeMarkerDiscount($billable, 0))->toBe(0)
        ->and($coupons->computeMarkerDiscount($billable, -100))->toBe(0);
});

it('says which invoice lines it takes off', function (): void {
    $billable = waiverCeilingBillable(granted: ['sso', 'crm'], active: ['sso', 'crm']);
    $marker = app(PlanWaiver::class)->marker($billable);

    expect(PlanWaiver::appliesTo($marker, ['sso', 'crm']))
        ->toBe(['plan', 'addon:sso', 'addon_seats:sso', 'addon:crm', 'addon_seats:crm'])
        // A granted addon that was switched off is no longer one of them …
        ->and(PlanWaiver::appliesTo($marker, ['crm']))->toBe(['plan', 'addon:crm', 'addon_seats:crm'])
        // … and an addon the tenant bought never is.
        ->and(PlanWaiver::appliesTo(app(PlanWaiver::class)->marker(waiverCeilingBillable([], ['sso'])), ['sso']))->toBe(['plan']);
});
