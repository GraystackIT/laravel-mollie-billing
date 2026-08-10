<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\IntervalNotOfferedException;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * A plan may be sold in one interval only — `billing:check-config` accepts
 * `intervals` with just `monthly`. The trap is that the price getters cannot say
 * "not sold": `basePriceNet()` answers 0 for a missing interval, exactly like a
 * genuinely free plan. So picking the interval the plan does not offer priced the
 * order at zero, and the zero-amount branch of checkout activated the **paid**
 * plan as a Local subscription — every feature, indefinitely, no payment and no
 * Mollie call.
 *
 * `monthlyonly` below is 2900 net monthly and has no yearly block at all.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.monthlyonly', [
        'name' => 'Monthly Only',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['reports'],
        'allowed_addons' => ['monthlyaddon'],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.addons.monthlyaddon', [
        'name' => 'Monthly Addon',
        'feature_keys' => ['extra-reports'],
        'intervals' => ['monthly' => ['price_net' => 500]],
    ]);

    config()->set('mollie-billing-plans.features', [
        'reports' => ['name' => 'Reports', 'description' => null],
        'extra-reports' => ['name' => 'Extra reports', 'description' => null],
    ]);
});

function intervalBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Interval Co',
        'email' => uniqid('interval-').'@x.test',
        'billing_country' => 'DE',
    ]);

    return $b->refresh();
}

it('tells a missing interval apart from a free one', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    // Both answer 0 — which is exactly why the price alone cannot be trusted.
    expect($catalog->basePriceNet('monthlyonly', 'yearly'))->toBe(0)
        ->and($catalog->basePriceNet('free', 'yearly'))->toBe(0);

    expect($catalog->planOffersInterval('monthlyonly', 'monthly'))->toBeTrue()
        ->and($catalog->planOffersInterval('monthlyonly', 'yearly'))->toBeFalse()
        ->and($catalog->planOffersInterval('free', 'yearly'))->toBeTrue()
        ->and($catalog->addonOffersInterval('monthlyaddon', 'monthly'))->toBeTrue()
        ->and($catalog->addonOffersInterval('monthlyaddon', 'yearly'))->toBeFalse();
});

it('refuses to check out a paid plan on an interval it does not offer', function (): void {
    $billable = intervalBillable();

    // The gross the component would compute for that pair: nothing is priced, so 0.
    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'monthlyonly',
        'interval' => 'yearly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 0,
    ]))->toThrow(IntervalNotOfferedException::class);

    // Nothing was activated and nothing was billed — this is the whole point.
    $billable->refresh();
    expect($billable->subscription_status)->toBe(SubscriptionStatus::New)
        ->and($billable->subscription_plan_code)->toBeNull()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse()
        ->and($billable->hasPlanFeature('reports'))->toBeFalse()
        ->and(BillingInvoice::query()->count())->toBe(0);
});

it('refuses an addon that is not sold in the requested interval', function (): void {
    config()->set('mollie-billing-plans.plans.monthlyonly.intervals.yearly', [
        'base_price_net' => 29000, 'seat_price_net' => null, 'included_usages' => [],
    ]);

    $billable = intervalBillable();

    // The plan is now sold yearly; the addon still is not, so it would ride along free.
    expect(fn () => app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'monthlyonly',
        'interval' => 'yearly',
        'addon_codes' => ['monthlyaddon'],
        'extra_seats' => 0,
        'amount_gross' => 34510,
    ]))->toThrow(IntervalNotOfferedException::class);

    expect($billable->refresh()->hasPlanFeature('extra-reports'))->toBeFalse();
});

it('still activates a genuinely free plan locally', function (): void {
    $billable = intervalBillable();

    $result = app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'free',
        'interval' => 'yearly',
        'addon_codes' => [],
        'extra_seats' => 0,
        'amount_gross' => 0,
    ]);

    // The guard must not break the path it sits next to: a free plan priced at 0
    // is still a free plan, and yearly is an interval it declares.
    expect($result['checkout_url'])->toBeNull();
    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('free');
});

it('accepts the interval the plan does offer', function (): void {
    $billable = intervalBillable();

    // Guard passes; the flow then fails later for want of a Mollie fake, which is
    // proof enough that it got past the interval check.
    try {
        app(StartSubscriptionCheckout::class)->handle($billable, [
            'plan_code' => 'monthlyonly',
            'interval' => 'monthly',
            'addon_codes' => ['monthlyaddon'],
            'extra_seats' => 0,
            'amount_gross' => 4046,
        ]);
    } catch (\Throwable $e) {
        expect($e)->not->toBeInstanceOf(IntervalNotOfferedException::class);
    }
});
