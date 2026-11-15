<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * What an operator does from the admin panel to give a tenant more free time — without
 * creating a coupon by hand and sending a code.
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
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);

    AdminExtensionMollieLog::$sent = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        AdminExtensionMollieLog::$sent[] = $request;

        return (object) ['id' => 'sub_admin'];
    });
});

final class AdminExtensionMollieLog
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
}

function adminPaying(array $addons = [], int $seats = 2): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Paying Co', 'email' => uniqid('pay-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_admin', 'seat_count' => $seats],
        'mollie_customer_id' => 'cust_admin',
        'mollie_mandate_id' => 'mdt_admin',
    ])->save();

    return $billable->fresh();
}

function adminGifted(int $days = 30): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gifted Co', 'email' => uniqid('gift-').'@x.test', 'billing_country' => 'AT']);
    $service = app(CouponService::class);
    $code = 'GRANT-'.strtoupper(bin2hex(random_bytes(3)));
    $coupon = $service->accessGrantCoupon($code, 'pro', 'monthly', [], $days);
    $service->redeem($coupon, $billable->fresh(), []);

    return $billable->fresh();
}

it('extends a paying subscription by days: the next charge moves later, by exactly that', function (): void {
    $billable = adminPaying();
    $before = $billable->nextBillingDate();

    $redemption = app(AdminExtensionService::class)->extendByDays($billable, 14);

    $billable->refresh();

    expect($billable->nextBillingDate()->toDateString())->toBe($before->copy()->addDays(14)->toDateString())
        ->and($redemption->coupon->type)->toBe(CouponType::PeriodExtension)
        ->and(str_starts_with((string) $redemption->coupon->code, 'EXT-'))->toBeTrue()
        ->and($redemption->grant_days_added)->toBe(14);
});

it('extends a gift on a Local subscription by days', function (): void {
    $billable = adminGifted(days: 30);
    $before = $billable->getBillingSubscriptionEndsAt();

    app(AdminExtensionService::class)->extendByDays($billable, 14);

    expect($billable->fresh()->getBillingSubscriptionEndsAt()->toDateString())
        ->toBe($before->copy()->addDays(14)->toDateString());
});

it('leaves no redeemable code behind when the extension is refused', function (): void {
    // No subscription at all: a period extension has nothing to extend.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Nobody', 'email' => 'nobody@x.test']);

    expect(fn () => app(AdminExtensionService::class)->extendByDays($billable, 14))
        ->toThrow(InvalidCouponException::class);

    expect(Coupon::query()->where('code', 'like', 'EXT-%')->count())->toBe(0);
});

it('gives free plan periods: Mollie collects only the extras for that long', function (): void {
    // Pro 50.00 + SSO 15.00 + one extra seat 5.00.
    $billable = adminPaying(addons: ['sso'], seats: 3);

    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $billable->refresh();
    $marker = app(PlanWaiver::class)->marker($billable);

    expect($marker)->not->toBeNull()
        ->and($marker['max_applications'])->toBe(2)
        ->and($marker['on_expiry'])->toBe(PlanWaiver::ON_EXPIRY_CONTINUE_PAID)
        ->and(app(CouponService::class)->computeMarkerDiscount($billable, 7000))->toBe(5000);

    // SSO 15.00 + seat 5.00, plus 20 % — on the regular cycle.
    $patch = AdminExtensionMollieLog::lastPatch();
    expect($patch)->not->toBeNull()
        ->and(AdminExtensionMollieLog::property($patch, 'amount')->value)->toBe('24.00')
        ->and(AdminExtensionMollieLog::property($patch, 'startDate'))->toBeNull();
});

it('defers the charge instead of charging zero when nothing but the plan is billed', function (): void {
    $billable = adminPaying();

    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $billable->refresh();
    $marker = app(PlanWaiver::class)->marker($billable);
    $patch = AdminExtensionMollieLog::lastPatch();

    // Mollie cannot hold 0: full price, first charge the day after the free periods.
    expect(AdminExtensionMollieLog::property($patch, 'amount')->value)->toBe('60.00')
        ->and(ChargeDeferral::until($billable)->toDateString())
        ->toBe(Carbon::parse((string) $marker['valid_until'])->addDay()->toDateString());
});

it('appends free periods to a waiver that is already running', function (): void {
    $billable = adminPaying(addons: ['sso']);
    $service = app(AdminExtensionService::class);

    $service->freePlanPeriods($billable, 2);
    $first = Carbon::parse((string) app(PlanWaiver::class)->marker($billable->fresh())['valid_until']);

    $service->freePlanPeriods($billable->fresh(), 3);
    $marker = app(PlanWaiver::class)->marker($billable->fresh());

    expect($marker['max_applications'])->toBe(5)
        ->and(Carbon::parse((string) $marker['valid_until'])->toDateString())
        // Whole billing periods on the subscription's own cycle, not 30-day blocks.
        ->toBe($first->copy()->addDay()->addMonthsNoOverflow(3)->subDay()->toDateString());
});

it('refuses free plan periods next to an ordinary recurring coupon', function (): void {
    $billable = adminPaying();
    $coupons = app(CouponService::class);

    $coupon = $coupons->create([
        'code' => 'REC20',
        'name' => '20%',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');

    expect(fn () => app(AdminExtensionService::class)->freePlanPeriods($billable->fresh(), 2))
        ->toThrow(InvalidCouponException::class);

    expect(Coupon::query()->where('code', 'like', 'PLANFREE-%')->count())->toBe(0);
});

it('turns free plan periods into free time on a gifted Local subscription', function (): void {
    $billable = adminGifted(days: 30);
    $before = $billable->getBillingSubscriptionEndsAt();

    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $after = $billable->fresh()->getBillingSubscriptionEndsAt();

    expect($after->toDateString())->toBe($before->copy()->addMonthsNoOverflow(2)->toDateString());
});

it('extends a running gift on a Local subscription and records what happens at its end', function (): void {
    $billable = adminGifted(days: 30);
    $before = $billable->getBillingSubscriptionEndsAt();

    $newEnd = app(AdminExtensionService::class)->extendGrant($billable, 60, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    $billable->refresh();

    expect($newEnd->toDateString())->toBe($before->copy()->addDays(60)->toDateString())
        ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe($newEnd->toDateString())
        ->and($billable->getBillingSubscriptionMeta()['grant_on_expiry'])->toBe('continue_paid');
});

it('extends a gift that lives on as a plan waiver after the tenant bought extras', function (): void {
    $billable = adminPaying(addons: ['sso']);
    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-KEPT', 'pro', 'monthly', [], 30);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(30), null, PlanWaiver::ON_EXPIRY_END);

    $before = Carbon::parse((string) app(PlanWaiver::class)->marker($billable->fresh())['valid_until']);

    $newEnd = app(AdminExtensionService::class)->extendGrant($billable->fresh(), 45);

    expect($newEnd->toDateString())->toBe($before->copy()->addDays(45)->toDateString());

    // The extras are still what Mollie collects.
    $patch = AdminExtensionMollieLog::lastPatch();
    expect(AdminExtensionMollieLog::property($patch, 'amount')->value)->toBe('18.00');
});

it('refuses to extend a grant where there is none', function (): void {
    $billable = adminPaying();

    app(AdminExtensionService::class)->extendGrant($billable, 30);
})->throws(InvalidSubscriptionStateException::class);

it('takes the plan waiver away on revoke and returns Mollie to the full price', function (): void {
    $billable = adminPaying(addons: ['sso']);
    $coupons = app(CouponService::class);

    $redemption = app(AdminExtensionService::class)->freePlanPeriods($billable, 3);
    expect(app(PlanWaiver::class)->marker($billable->fresh()))->not->toBeNull();

    $coupons->revokeGrant($redemption, 'changed our mind');

    $billable->refresh();
    $patch = AdminExtensionMollieLog::lastPatch();

    expect(app(PlanWaiver::class)->marker($billable))->toBeNull()
        ->and($redemption->fresh()->isRevoked())->toBeTrue()
        // Pro 50.00 + SSO 15.00, plus 20 %.
        ->and(AdminExtensionMollieLog::property($patch, 'amount')->value)->toBe('78.00');
});

it('brings a subscription back when the gift is extended after it had already been set to end', function (): void {
    $billable = adminPaying(addons: ['sso']);
    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-ENDING', 'pro', 'monthly', [], 5);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(5), null, PlanWaiver::ON_EXPIRY_END);

    // The gift did not reach the next charge, so the subscription was cancelled with it.
    app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class)->handle($billable->fresh(), false, 'grant_expired');
    expect($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);

    AdminExtensionMollieLog::$sent = [];
    app(AdminExtensionService::class)->extendGrant($billable->fresh(), 60);

    $billable->refresh();

    // Running again, the gift intact, and Mollie holds a subscription for the extras only.
    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(app(PlanWaiver::class)->marker($billable))->not->toBeNull();

    $created = collect(AdminExtensionMollieLog::$sent)
        ->first(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CreateSubscriptionRequest);

    expect($created)->not->toBeNull()
        ->and(AdminExtensionMollieLog::property($created, 'amount')->value)->toBe('18.00');
});
