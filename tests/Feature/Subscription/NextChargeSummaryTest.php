<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingMoney;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * "Next charge: date and amount" — read from the sources the charge is built from, so
 * what a customer is shown is what Mollie holds.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
        'feature_keys' => [], 'allowed_addons' => ['sso', 'crm'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO', 'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]],
    ]);
    config()->set('mollie-billing-plans.addons.crm', [
        'name' => 'CRM', 'feature_keys' => [], 'included_seats' => 1,
        'intervals' => ['monthly' => ['price_net' => 1000, 'seat_price_net' => 200]],
    ]);
});

function nextChargeBillable(array $addons = [], int $seats = 2, array $overrides = [], string $country = 'AT'): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(['name' => 'Next Charge Co', 'email' => uniqid('next-').'@x.test', 'billing_country' => $country]);
    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_next', 'seat_count' => $seats],
        'mollie_customer_id' => 'cst_next',
        'mollie_mandate_id' => 'mdt_next',
    ], $overrides))->save();

    return $b->fresh();
}

/** The amount the package would send Mollie for this billable right now. */
function nextChargeMollieAmount(TestBillable $billable): string
{
    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        $captured = $request;

        return (object) ['id' => 'sub_next'];
    });

    app(PlanWaiver::class)->syncMollie($billable);

    expect($captured)->toBeInstanceOf(UpdateSubscriptionRequest::class);

    $p = (new ReflectionClass($captured))->getProperty('amount');
    $p->setAccessible(true);

    return (string) $p->getValue($captured)->value;
}

it('states the date and the amount of a plain subscription', function (): void {
    $billable = nextChargeBillable();
    $summary = app(NextChargeSummary::class)->for($billable);

    expect($summary['state'])->toBe(NextChargeSummary::STATE_SCHEDULED)
        ->and($summary['date']->toDateString())->toBe($billable->nextBillingDate()->toDateString())
        ->and($summary['net'])->toBe(5000)
        ->and($summary['gross'])->toBe(6000)
        ->and($summary['vat'])->toBe(1000)
        ->and($summary['discount_net'])->toBe(0)
        ->and($summary['deferred'])->toBeFalse()
        ->and(array_column($summary['lines'], 'kind'))->toBe(['plan']);
});

it('breaks the amount down into plan, extra seats, addon and addon seats', function (): void {
    // 4 seats: 2 extra at 5.00; SSO 15.00; CRM 10.00 + 3 billed seats (1 included) at 2.00.
    $billable = nextChargeBillable(addons: ['sso', 'crm'], seats: 4);
    $summary = app(NextChargeSummary::class)->for($billable);

    expect(array_column($summary['lines'], 'kind'))->toBe(['plan', 'seats', 'addon', 'addon', 'addon_seats'])
        ->and($summary['net'])->toBe(5000 + 1000 + 1500 + 1000 + 600)
        ->and(array_sum(array_column($summary['lines'], 'net')))->toBe($summary['net']);
});

it('shows exactly what Mollie holds', function (array $addons, int $seats, string $country): void {
    $billable = nextChargeBillable(addons: $addons, seats: $seats, country: $country);

    $gross = app(NextChargeSummary::class)->for($billable)['gross'];

    expect(BillingMoney::mollieAmount($gross, 'EUR'))->toBe(nextChargeMollieAmount($billable));
})->with([
    'plan only, AT' => [[], 2, 'AT'],
    'seats and addons, AT' => [['sso', 'crm'], 5, 'AT'],
    'seats and addons, DE' => [['sso', 'crm'], 5, 'DE'],
    'one addon, DE' => [['sso'], 3, 'DE'],
]);

it('takes a running coupon off, and shows what Mollie holds with it', function (): void {
    $billable = nextChargeBillable(addons: ['sso']);
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'HALF', 'name' => 'Half', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 6500, 'monthly');
    $billable = $billable->fresh();

    $summary = app(NextChargeSummary::class)->for($billable);

    expect($summary['discount_net'])->toBe(3250)
        ->and($summary['discount_code'])->toBe('HALF')
        ->and($summary['net'])->toBe(3250)
        ->and(BillingMoney::mollieAmount($summary['gross'], 'EUR'))->toBe(nextChargeMollieAmount($billable));
});

it('does not promise a discount that ends before the charge', function (): void {
    $billable = nextChargeBillable();
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'ENDSSOON', 'name' => 'Ends soon', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
        // Valid for five more days; the next charge is in about twenty.
        'valid_until' => BillingTime::nowUtc()->addDays(5)->toIso8601String(),
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');

    $summary = app(NextChargeSummary::class)->for($billable->fresh());

    expect($summary['discount_net'])->toBe(0)
        ->and($summary['net'])->toBe(5000);
});

it('shows the deferred date and the full price under a fully covering coupon', function (): void {
    $billable = nextChargeBillable();
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FREE3', 'name' => 'Free', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');

    $billable = $billable->fresh();
    $deferred = ChargeDeferral::until($billable);

    $summary = app(NextChargeSummary::class)->for($billable);

    // Nothing is charged at the period end; the first charge is the full price, on the
    // day after the coupon.
    expect($deferred)->not->toBeNull()
        ->and($summary['deferred'])->toBeTrue()
        ->and($summary['date']->toDateString())->toBe($deferred->toDateString())
        ->and($summary['date']->greaterThan($billable->nextBillingDate()))->toBeTrue()
        ->and($summary['net'])->toBe(5000)
        ->and($summary['discount_net'])->toBe(0);
});

it('shows a gifted plan as free and the paid extras as the amount', function (): void {
    $billable = nextChargeBillable(addons: ['sso'], seats: 3);
    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-NEXT', 'pro', 'monthly', [], 90);
    $until = BillingTime::nowUtc()->addDays(90);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], $until, null, PlanWaiver::ON_EXPIRY_END);
    $billable = $billable->fresh();

    $summary = app(NextChargeSummary::class)->for($billable);

    // Plan 50.00 waived; one extra seat 5.00 and SSO 15.00 are charged.
    expect($summary['discount_net'])->toBe(5000)
        ->and($summary['plan_free_until']->toDateString())->toBe($until->toDateString())
        ->and($summary['discount_code'])->toBeNull()
        ->and($summary['net'])->toBe(2000)
        ->and($summary['gross'])->toBe(2400)
        ->and(BillingMoney::mollieAmount($summary['gross'], 'EUR'))->toBe(nextChargeMollieAmount($billable));
});

it('shows the trial end as the first charge', function (): void {
    $trialEnd = BillingTime::nowUtc()->addDays(9);
    $billable = nextChargeBillable(overrides: [
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => $trialEnd,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
    ]);

    $summary = app(NextChargeSummary::class)->for($billable);

    expect($summary['state'])->toBe(NextChargeSummary::STATE_SCHEDULED)
        ->and($summary['date']->toDateString())->toBe($trialEnd->toDateString())
        ->and($summary['gross'])->toBe(6000);
});

it('says that nothing is scheduled where nothing is', function (array $overrides, string $state): void {
    $billable = nextChargeBillable(overrides: $overrides);

    $summary = app(NextChargeSummary::class)->for($billable);

    expect($summary['state'])->toBe($state)
        ->and($summary['date'])->toBeNull()
        ->and($summary['gross'])->toBe(0);
})->with([
    'free plan' => [['subscription_source' => SubscriptionSource::Local], NextChargeSummary::STATE_FREE],
    'cancelled' => [['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => now()->addDays(12)], NextChargeSummary::STATE_ENDING],
    'past due' => [['subscription_status' => SubscriptionStatus::PastDue], NextChargeSummary::STATE_PAST_DUE],
    'expired' => [['subscription_status' => SubscriptionStatus::Expired], NextChargeSummary::STATE_NONE],
    'no plan' => [['subscription_plan_code' => null], NextChargeSummary::STATE_NONE],
]);

it('flags a change that is scheduled for the period end', function (): void {
    $billable = nextChargeBillable(overrides: [
        'subscription_meta' => ['mollie_subscription_id' => 'sub_next', 'seat_count' => 2, 'scheduled_change' => ['plan_code' => 'pro']],
    ]);

    expect(app(NextChargeSummary::class)->for($billable)['has_scheduled_change'])->toBeTrue();
});

it('falls back to the net when the country cannot be priced, instead of failing the screen', function (): void {
    $billable = nextChargeBillable(country: 'US');

    $summary = app(NextChargeSummary::class)->for($billable);

    expect($summary['state'])->toBe(NextChargeSummary::STATE_SCHEDULED)
        ->and($summary['net'])->toBe(5000)
        ->and($summary['gross'])->toBe(5000);
});

it('shows date, amount and breakdown on the customer dashboard', function (): void {
    \GraystackIT\MollieBilling\Facades\MollieBilling::authUsing(fn (): bool => true);

    $billable = nextChargeBillable(addons: ['sso'], seats: 3);
    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-DASH', 'pro', 'monthly', [], 90);
    $until = BillingTime::nowUtc()->addDays(90);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], $until, null, PlanWaiver::ON_EXPIRY_END);
    $billable = $billable->fresh();
    \GraystackIT\MollieBilling\Facades\MollieBilling::resolveBillableUsing(fn () => $billable);

    \Livewire\Livewire::test('mollie-billing::dashboard')
        ->assertSee(__('billing::portal.next_charge.title'))
        ->assertSee(BillingTime::display($billable->nextBillingDate(), $billable)->translatedFormat('d. M Y'))
        // 20.00 net of extras + 20 %.
        ->assertSee(BillingMoney::format(2400))
        ->assertSee(__('billing::portal.next_charge.plan_free_until', [
            'date' => BillingTime::display($until, $billable)->translatedFormat('d. M Y'),
        ]))
        ->assertSee(__('billing::portal.next_charge.usage_hint'));
});

it('shows date and amount on the admin subscription tab', function (): void {
    $billable = nextChargeBillable(addons: ['sso']);

    \Livewire\Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()])
        ->assertSee(__('billing::portal.next_charge.title'))
        // 65.00 net + 20 %.
        ->assertSee(BillingMoney::format(7800));
});

it('tells a free-plan customer on the dashboard that nothing is charged', function (): void {
    \GraystackIT\MollieBilling\Facades\MollieBilling::authUsing(fn (): bool => true);

    $billable = nextChargeBillable(overrides: ['subscription_source' => SubscriptionSource::Local]);
    \GraystackIT\MollieBilling\Facades\MollieBilling::resolveBillableUsing(fn () => $billable);

    \Livewire\Livewire::test('mollie-billing::dashboard')
        ->assertSee(__('billing::portal.next_charge.free'));
});
