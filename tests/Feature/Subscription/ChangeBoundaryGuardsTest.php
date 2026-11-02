<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\AddonNotAllowedException;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Laravel\Facades\Mollie;

// The portal components check addon eligibility, the seat ceiling and the replay flag
// themselves — but `UpdateSubscription::update()`, `enableBillingAddon()` and
// `StartSubscriptionCheckout::handle()` are documented entry points an app calls
// directly, and the service layer let all three through. Every guard below throws before
// any Mollie request, which is what `send()->never()` pins.

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.team', [
        'name' => 'Team',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => ['monthlyonly'],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.premium', [
        'name' => 'Premium',
        'feature_keys' => ['premium-reports'],
        'intervals' => ['monthly' => ['price_net' => 900], 'yearly' => ['price_net' => 9000]],
    ]);

    config()->set('mollie-billing-plans.addons.monthlyonly', [
        'name' => 'Monthly only',
        'feature_keys' => ['extra'],
        'intervals' => ['monthly' => ['price_net' => 300]],
    ]);

    config()->set('mollie-billing-plans.features', [
        'premium-reports' => ['name' => 'Premium reports', 'description' => null],
        'extra' => ['name' => 'Extra', 'description' => null],
    ]);

    config()->set('mollie-billing.max_seats', 50);

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->never();
});

function boundaryBillable(string $interval = 'monthly', array $addons = [], int $periodAgeDays = 5): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Boundary Co',
        'email' => uniqid('boundary-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'team',
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($periodAgeDays),
        'active_addon_codes' => $addons,
        'mollie_customer_id' => 'cst_boundary',
        'mollie_mandate_id' => 'mdt_boundary',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_boundary', 'seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('refuses to enable an addon the current plan does not allow', function (): void {
    $billable = boundaryBillable();

    expect(fn () => $billable->enableBillingAddon('premium'))
        ->toThrow(AddonNotAllowedException::class);

    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe([])
        ->and($billable->fresh()->hasPlanFeature('premium-reports'))->toBeFalse();
});

it('refuses an unknown addon code through the update API', function (): void {
    $billable = boundaryBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['addons' => ['does-not-exist']]))
        ->toThrow(AddonNotAllowedException::class);
});

it('refuses to enable an allowed addon on an interval it is not sold in', function (): void {
    // `addonPriceNet()` answers 0 for a missing interval, so on yearly this addon would be
    // switched on, feature and all, at no charge.
    $billable = boundaryBillable('yearly');

    expect(fn () => $billable->enableBillingAddon('monthlyonly'))
        ->toThrow(IntervalNotOfferedException::class);

    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe([]);
});

it('does not re-judge an addon the billable already holds', function (): void {
    // Grandfathered: no longer allowed on the plan, but it was sold under the old rules.
    // A change that leaves it in place must not be refused for it.
    $billable = boundaryBillable('monthly', ['premium']);

    $context = new \GraystackIT\MollieBilling\Services\Billing\SubscriptionChangeContext(
        currentPlan: 'team', currentInterval: 'monthly', currentSeats: 1, currentAddons: ['premium'], currentNet: 1900,
        newPlan: 'team', newInterval: 'monthly', newSeats: 1, newAddons: ['premium'], newNet: 1900,
        planChanged: false, intervalChanged: false,
        prorataChargeNet: 0, prorataCreditNet: 0, isMollie: true,
    );

    app(\GraystackIT\MollieBilling\Services\Billing\ValidateSubscriptionChange::class)->validate($billable, $context);

    expect($context->newAddons)->toBe(['premium']);
});

it('enforces max_seats on an explicit seat count passed to the update API', function (): void {
    $billable = boundaryBillable();

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 5000]))
        ->toThrow(SeatCountExceedsMaximumException::class);

    expect($billable->fresh()->getBillingSeatCount())->toBe(1);
});

it('does not take the internal replay flag from array data', function (): void {
    expect(SubscriptionUpdateRequest::from(['seats' => 3, 'internal' => true])->internal)->toBeFalse()
        ->and(SubscriptionUpdateRequest::from(['seats' => 3])->asInternalReentry()->internal)->toBeTrue();
});

it('keeps the lapsed-period guard for a caller that sends internal=true', function (): void {
    // 40 days into a monthly period with no renewal: the prorata factor is 0, so a seat
    // increase would be applied for free. `internal` is the scheduler's exemption, and an
    // array an app forwards from request data must not be able to claim it.
    $billable = boundaryBillable('monthly', [], periodAgeDays: 40);

    expect(fn () => app(UpdateSubscription::class)->update($billable, ['seats' => 3, 'internal' => true]))
        ->toThrow(InvalidSubscriptionStateException::class);
});

it('refuses a checkout addon the selected plan does not allow', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'New Co',
        'email' => uniqid('new-').'@x.test',
        'billing_country' => 'AT',
    ]);

    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'team',
        'interval' => 'monthly',
        'addon_codes' => ['premium'],
        'extra_seats' => 0,
        'amount_gross' => 2280,
    ]))->toThrow(AddonNotAllowedException::class);
});
