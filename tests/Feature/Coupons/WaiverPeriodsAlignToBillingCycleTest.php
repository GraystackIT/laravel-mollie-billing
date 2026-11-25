<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * "Plan free for N periods" is a number of *charges* that are not collected.
 *
 * The waiver's end date used to be counted from the moment it was given (`now + N × 30
 * + 1` days), the way a recurring coupon's is — but a coupon is redeemed at checkout,
 * i.e. at a period boundary, and a waiver is given in the middle of a period. On a
 * subscription that bills nothing but the plan the charge is deferred to that date, so
 * one free period given on day 2 of a month moved the charge by three days: Mollie
 * collected the full price on day 33 instead of day 30, and the tenant had been told the
 * plan was free for a month. With a paid addon on the same subscription the very same
 * action waived a whole renewal.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 2,
        'feature_keys' => [],
        'allowed_addons' => ['sso'],
        'intervals' => [
            'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]],
    ]);

    WaiverCycleMollieLog::$sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        WaiverCycleMollieLog::$sent[] = $request;

        return (object) ['id' => 'sub_cycle'];
    });
});

final class WaiverCycleMollieLog
{
    /** @var list<object> */
    public static array $sent = [];

    public static function lastPatch(): ?UpdateSubscriptionRequest
    {
        $patches = array_values(array_filter(self::$sent, fn ($r) => $r instanceof UpdateSubscriptionRequest));

        return $patches === [] ? null : end($patches);
    }

    public static function property(object $request, string $name): mixed
    {
        $p = (new ReflectionClass($request))->getProperty($name);
        $p->setAccessible(true);

        return $p->getValue($request);
    }

    public static function startDate(?UpdateSubscriptionRequest $request): ?string
    {
        $date = $request !== null ? self::property($request, 'startDate') : null;

        return $date !== null ? (string) $date : null;
    }
}

function waiverCyclePaying(int $daysIntoPeriod, array $addons = [], string $interval = 'monthly'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Cycle Co', 'email' => uniqid('cyc-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysIntoPeriod),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_cycle', 'seat_count' => 2],
        'mollie_customer_id' => 'cust_cycle',
        'mollie_mandate_id' => 'mdt_cycle',
    ])->save();

    return $billable->fresh();
}

/**
 * The regular charge `$n` periods after the next one (0 = the next one itself).
 *
 * Counted from the period start, `start + (n + 1) × interval` clamped to the month, and
 * not by adding one interval at a time to the next charge: a cycle on the 31st is charged
 * on 30 November and then on 31 December, not on 30 December. Stepping from charge to
 * charge carried a short month's clamp forward, and the expectations turned red only on
 * the days whose period happened to start on the 29th, 30th or 31st.
 */
function waiverCycleCharge(TestBillable $billable, int $n): Carbon
{
    $start = Carbon::instance($billable->subscription_period_starts_at);

    return $billable->subscription_interval === SubscriptionInterval::Yearly
        ? $start->copy()->addYearsNoOverflow($n + 1)
        : $start->copy()->addMonthsNoOverflow($n + 1);
}

it('defers a plan-only subscription by whole periods, whenever in the period the gift is made', function (int $daysIntoPeriod, int $periods): void {
    $billable = waiverCyclePaying($daysIntoPeriod);

    // N charges are skipped: the first one collected is the regular one N periods on.
    $expected = waiverCycleCharge($billable, $periods);

    app(AdminExtensionService::class)->freePlanPeriods($billable, $periods);

    $billable->refresh();

    $patch = WaiverCycleMollieLog::lastPatch();

    expect(ChargeDeferral::until($billable)?->toDateString())->toBe($expected->toDateString())
        ->and(WaiverCycleMollieLog::startDate($patch))->toBe($expected->toDateString())
        // Mollie cannot hold 0, so it keeps the full price behind the deferred date.
        ->and(WaiverCycleMollieLog::property($patch, 'amount')->value)->toBe('60.00');
})->with([
    'day 1, one period' => [1, 1],
    'day 10, one period' => [10, 1],
    'day 25, one period' => [25, 1],
    'day 10, three periods' => [10, 3],
]);

it('keeps a waiver alive across exactly N renewals when extras are billed', function (int $daysIntoPeriod): void {
    $billable = waiverCyclePaying($daysIntoPeriod, addons: ['sso']);
    $c1 = waiverCycleCharge($billable, 1);
    $c2 = waiverCycleCharge($billable, 2);

    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $billable->refresh();
    $coupons = app(CouponService::class);
    $validUntil = Carbon::parse((string) app(PlanWaiver::class)->marker($billable)['valid_until']);

    // Still running when the second waived renewal settles a few days late (SEPA) …
    expect($validUntil->greaterThan($c1->copy()->addDays(5)))->toBeTrue()
        // … and over before the third charge is created.
        ->and($validUntil->lessThan($c2))->toBeTrue()
        ->and($coupons->markerExpired($billable, $c1))->toBeFalse()
        ->and($coupons->markerExpired($billable, $c2))->toBeTrue();
})->with([1, 15, 27]); // not 29: February has no 29th day of a period, its renewal would lie in the past

it('appends whole periods to a deferred waiver', function (): void {
    $billable = waiverCyclePaying(10);
    $expected = waiverCycleCharge($billable, 3);
    $service = app(AdminExtensionService::class);

    $service->freePlanPeriods($billable, 1);
    $service->freePlanPeriods($billable->fresh(), 2);

    $billable->refresh();

    expect(ChargeDeferral::until($billable)?->toDateString())->toBe($expected->toDateString())
        ->and(app(PlanWaiver::class)->marker($billable)['max_applications'])->toBe(3);
});

it('appends free periods on the cycle day, not on the clamped day the charge was deferred to', function (string $now, string $first, string $resumes): void {
    // One free period moves the charge to the end of February, which is not the cycle's
    // day. The two appended periods must land where three given at once land.
    Carbon::setTestNow(Carbon::parse($now, 'UTC'));

    $billable = waiverCyclePaying(10);
    $service = app(AdminExtensionService::class);

    $service->freePlanPeriods($billable, 1);
    expect(ChargeDeferral::until($billable->fresh())?->toDateString())->toBe($first);

    $service->freePlanPeriods($billable->fresh(), 2);
    $billable->refresh();

    expect(ChargeDeferral::until($billable)?->toDateString())->toBe($resumes)
        ->and(WaiverCycleMollieLog::startDate(WaiverCycleMollieLog::lastPatch()))->toBe($resumes);
})->with([
    'cycle on the 29th' => ['2027-01-08 13:49:00', '2027-02-28', '2027-04-29'],
    'cycle on the 31st' => ['2027-01-10 13:49:00', '2027-02-28', '2027-04-30'],
    'cycle on the 30th, leap year' => ['2028-01-09 13:49:00', '2028-02-29', '2028-04-30'],
]);

it('counts a yearly waiver in years', function (): void {
    $billable = waiverCyclePaying(100, interval: 'yearly');
    $expected = waiverCycleCharge($billable, 1);

    app(AdminExtensionService::class)->freePlanPeriods($billable, 1);

    expect(ChargeDeferral::until($billable->fresh())?->toDateString())->toBe($expected->toDateString());
});

// ── A gift that ends on a date ──────────────────────────────────────────────

/*
 * "A renewal is plan-free when it falls before the gift's end, so a gift rounds up to
 * the end of its last period. There are no partial days." (docs/decisions.md)
 *
 * That held as long as extras were billed. Once the gift covered the whole charge, the
 * full price was parked behind `gift end + 1 day` — in the middle of the period the rule
 * says is free — and Mollie collected the plan up to a whole period early.
 */

function waiverCycleGift(TestBillable $billable, int $days, string $onExpiry = PlanWaiver::ON_EXPIRY_CONTINUE_PAID): void
{
    $coupon = app(CouponService::class)->accessGrantCoupon(
        'GIFT-CYC-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], $days, $onExpiry,
    );

    app(PlanWaiver::class)->grant(
        $billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays($days), null, $onExpiry,
    );
}

it('charges a fully covering gift again on the first renewal after it ends, not the day after', function (int $days, bool $fromRenewal, int $boundariesAhead): void {
    // Period started 10 days ago: the regular renewals are in 18–21, ~50, ~80 days —
    // 18 in February, so the cases around the renewal are counted from it.
    $billable = waiverCyclePaying(10);
    $expected = waiverCycleCharge($billable, $boundariesAhead);
    $giftDays = $fromRenewal
        ? (int) BillingTime::nowUtc()->startOfDay()->diffInDays(waiverCycleCharge($billable, 0)->startOfDay()) + $days
        : $days;

    waiverCycleGift($billable, $giftDays);
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $billable->refresh();
    $patch = WaiverCycleMollieLog::lastPatch();

    expect(WaiverCycleMollieLog::startDate($patch))->toBe($expected->toDateString())
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected->toDateString())
        ->and(WaiverCycleMollieLog::property($patch, 'amount')->value)->toBe('60.00');
})->with([
    'gift ends in 5 days, inside the running period' => [5, false, 0],
    'gift ends the day before the renewal' => [-1, true, 0],
    'gift ends 5 days after the renewal' => [5, true, 1],
    'gift ends in 60 days' => [60, false, 2],
]);

it('agrees with the renewal handler about which renewal is the first one charged', function (int $giftDays): void {
    // The same gift on a subscription with a paid addon: the renewal handler asks
    // `markerExpired()` per charge date. Whatever date the deferral picks for the
    // plan-only case must be the first one that question answers "expired" for.
    $covered = waiverCyclePaying(10);
    waiverCycleGift($covered, $giftDays);
    app(PlanWaiver::class)->syncMollie($covered->fresh());
    $deferredTo = ChargeDeferral::until($covered->fresh());

    $withAddon = waiverCyclePaying(10, addons: ['sso']);
    waiverCycleGift($withAddon, $giftDays);
    $withAddon->refresh();

    $coupons = app(CouponService::class);
    $firstCharged = null;

    for ($i = 0; $i < 12 && $firstCharged === null; $i++) {
        $at = waiverCycleCharge($withAddon, $i);

        if ($coupons->markerExpired($withAddon, $at)) {
            $firstCharged = $at;
        }
    }

    expect($deferredTo?->toDateString())->toBe($firstCharged?->toDateString());
})->with([5, 19, 25, 60, 95]);

it('still defers an ordinary 100% coupon to the day after it ends', function (): void {
    $billable = waiverCyclePaying(10);
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FULL3', 'type' => \GraystackIT\MollieBilling\Enums\CouponType::Recurring,
        'discount_type' => \GraystackIT\MollieBilling\Enums\DiscountType::Percentage, 'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $marker = $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'];

    expect(WaiverCycleMollieLog::startDate(WaiverCycleMollieLog::lastPatch()))
        ->toBe(Carbon::parse((string) $marker['valid_until'])->addDay()->toDateString());
});
