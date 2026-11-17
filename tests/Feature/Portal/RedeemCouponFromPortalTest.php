<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * One "redeem a code" field for the customer. It recognises what kind of code it was
 * given and does the right thing with it — or says, in a sentence the customer can act
 * on, why this code does not work here.
 *
 * Pro 50.00 (2 seats included) + SSO 15.00, Starter 20.00, 20 % VAT.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso'],
            'intervals' => [
                'monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => ['tokens' => 100]],
                'yearly' => ['base_price_net' => 50000, 'seat_price_net' => 5000, 'included_usages' => ['tokens' => 100]],
            ],
        ],
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500], 'yearly' => ['price_net' => 15000]]],
    ]);
    config()->set('mollie-billing-plans.products', [
        'token-pack' => ['name' => 'Token pack', 'price_net' => 1000, 'usage_type' => 'tokens', 'quantity' => 500],
    ]);

    PortalRedeemMollie::$sent = [];
    PortalRedeemMollie::$failNext = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if (PortalRedeemMollie::$failNext > 0) {
            PortalRedeemMollie::$failNext--;

            throw new RuntimeException('Mollie is unreachable');
        }

        PortalRedeemMollie::$sent[] = $request;

        return (object) ['id' => 'sub_portal_redeem'];
    });
});

final class PortalRedeemMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static int $failNext = 0;

    public static function lastPatch(): ?UpdateSubscriptionRequest
    {
        $patches = array_values(array_filter(self::$sent, fn ($r) => $r instanceof UpdateSubscriptionRequest));

        return $patches === [] ? null : end($patches);
    }

    public static function property(?object $request, string $name): mixed
    {
        if ($request === null) {
            return null;
        }

        $p = (new ReflectionClass($request))->getProperty($name);
        $p->setAccessible(true);

        return $p->getValue($request);
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function portalRedeemPaying(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Portal', 'email' => uniqid('pr-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => ['sso'],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_portal_redeem', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_portal_redeem',
        'mollie_mandate_id' => 'mdt_portal_redeem',
    ], $overrides))->save();

    return $billable->fresh();
}

function portalRedeemNobody(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Nobody', 'email' => uniqid('nb-').'@x.test', 'billing_country' => 'AT']);

    return $billable->fresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function portalRedeemRecurring(string $code, array $attributes = []): Coupon
{
    return app(CouponService::class)->create(array_merge([
        'code' => $code,
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'discount_periods' => 3,
    ], $attributes));
}

// ── A recurring discount on a running subscription ──────────────────────────

it('applies a recurring code to a running subscription: marker, Mollie amount, redemption', function (): void {
    $billable = portalRedeemPaying();
    $nextCharge = Carbon::instance($billable->nextBillingDate());
    $coupon = portalRedeemRecurring('SAVE20');

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'save20');

    $billable->refresh();
    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    $patch = PortalRedeemMollie::lastPatch();

    expect($outcome->redeemed)->toBeTrue()
        // Locked to what is billed today: Pro 50.00 + SSO 15.00.
        ->and($marker['coupon_id'] ?? null)->toBe($coupon->id)
        ->and($marker['base_amount_net'])->toBe(6500)
        // 65.00 − 20 % = 52.00, + 20 % VAT; an amount change does not move the date.
        ->and(PortalRedeemMollie::property($patch, 'amount')->value)->toBe('62.40')
        ->and(PortalRedeemMollie::property($patch, 'startDate'))->toBeNull()
        ->and($billable->nextBillingDate()->toIso8601String())->toBe($nextCharge->toIso8601String());

    // Nothing was charged now, so nothing was discounted now: the row records the grant.
    $row = CouponRedemption::query()->where('coupon_id', $coupon->id)->sole();

    expect($row->discount_amount_net)->toBe(0)
        ->and($coupon->fresh()->redemptions_count)->toBe(1);

    // The customer is told from when and for how long.
    expect($outcome->message)
        ->toContain('SAVE20')
        ->toContain(BillingTime::display($nextCharge, $billable)->translatedFormat('d. M Y'))
        ->toContain('3');
});

it('discounts exactly the promised number of charges, counted from the next one', function (int $daysIntoPeriod): void {
    // Wherever in the period the code is typed in: three renewals, not two and a
    // window that happens to close a day before the third.
    $billable = portalRedeemPaying(['subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysIntoPeriod)]);
    $start = Carbon::instance($billable->nextBillingDate());
    portalRedeemRecurring('THREE');

    app(RedeemCouponFromPortal::class)->handle($billable, 'THREE');

    $coupons = app(CouponService::class);
    $discounted = 0;

    try {
        for ($i = 0; $i < 6; $i++) {
            // Each renewal settles a day after it is due.
            Carbon::setTestNow($start->copy()->addMonthsNoOverflow($i)->addDay());

            if ($coupons->redeemRecurringCouponForRenewal($billable->fresh(), 6500, 0) !== null) {
                $discounted++;
            }
        }
    } finally {
        Carbon::setTestNow();
    }

    expect($discounted)->toBe(3);
})->with(['first day of the period' => 0, 'mid period' => 14, 'last day' => 29]);

it('says until when a date-limited recurring code discounts', function (): void {
    $billable = portalRedeemPaying();
    $until = BillingTime::nowUtc()->addDays(200);
    portalRedeemRecurring('UNTIL', ['discount_periods' => null, 'valid_until' => $until]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'UNTIL');

    expect($outcome->redeemed)->toBeTrue()
        ->and($outcome->message)->toContain(BillingTime::display($until, $billable)->translatedFormat('d. M Y'));
});

it('refuses a recurring code that would end before the next charge, without spending it', function (): void {
    $billable = portalRedeemPaying();
    $coupon = portalRedeemRecurring('TOOSHORT', ['discount_periods' => null, 'valid_until' => BillingTime::nowUtc()->addDays(5)]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'TOOSHORT');

    expect($outcome->redeemed)->toBeFalse()
        ->and($outcome->message)->toBe(__('billing::portal.coupon_recurring_ends_before_next_charge'))
        ->and($coupon->fresh()->redemptions_count)->toBe(0)
        ->and($billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon')
        ->and(PortalRedeemMollie::$sent)->toBe([]);
});

it('defers the charge when a recurring code covers everything, and says until when nothing is charged', function (): void {
    $billable = portalRedeemPaying(['active_addon_codes' => []]);
    portalRedeemRecurring('FREE2', ['discount_value' => 100, 'discount_periods' => 2]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'FREE2');

    $billable->refresh();
    $deferred = ChargeDeferral::until($billable);

    expect($outcome->redeemed)->toBeTrue()
        ->and($deferred)->not->toBeNull()
        // Mollie cannot hold 0: full price, behind the deferred date.
        ->and(PortalRedeemMollie::property(PortalRedeemMollie::lastPatch(), 'amount')->value)->toBe('60.00')
        ->and((string) PortalRedeemMollie::property(PortalRedeemMollie::lastPatch(), 'startDate'))->toBe($deferred->toDateString())
        // Two charges are skipped: the deferred date lies past the second one.
        ->and($deferred->greaterThan(Carbon::instance($billable->nextBillingDate())->addMonthNoOverflow()))->toBeTrue()
        ->and($outcome->message)->toContain(BillingTime::display($deferred, $billable)->translatedFormat('d. M Y'));
});

it('keeps the discount and queues the Mollie PATCH when Mollie is unreachable', function (): void {
    $billable = portalRedeemPaying();
    portalRedeemRecurring('LATER');

    PortalRedeemMollie::$failNext = 1;
    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'LATER');

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    expect($outcome->redeemed)->toBeTrue()
        ->and($pending)->toBeArray()
        ->and($billable->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon');

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    expect(PortalRedeemMollie::property(PortalRedeemMollie::lastPatch(), 'amount')->value)->toBe('62.40')
        ->and($billable->fresh()->getBillingSubscriptionMeta())->toHaveKey('active_recurring_coupon');
});

it('explains why a recurring code cannot be applied, and changes nothing', function (Closure $arrange, string $messageKey): void {
    $billable = $arrange();
    $metaBefore = $billable->fresh()->getBillingSubscriptionMeta();

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable->fresh(), 'NOPE20');

    expect($outcome->redeemed)->toBeFalse()
        ->and($outcome->message)->toBe(__($messageKey))
        ->and($billable->fresh()->getBillingSubscriptionMeta())->toBe($metaBefore)
        ->and(CouponRedemption::query()->whereHas('coupon', fn ($q) => $q->where('code', 'NOPE20'))->count())->toBe(0)
        ->and(PortalRedeemMollie::$sent)->toBe([]);
})->with([
    'no subscription at all' => [function () {
        portalRedeemRecurring('NOPE20');

        return portalRedeemNobody();
    }, 'billing::portal.coupon_recurring_needs_paid_subscription'],
    'a free (local) subscription' => [function () {
        portalRedeemRecurring('NOPE20');

        return portalRedeemPaying(['subscription_source' => SubscriptionSource::Local, 'subscription_meta' => []]);
    }, 'billing::portal.coupon_recurring_needs_paid_subscription'],
    'a cancelled subscription' => [function () {
        portalRedeemRecurring('NOPE20');

        return portalRedeemPaying(['subscription_status' => SubscriptionStatus::Cancelled, 'subscription_ends_at' => BillingTime::nowUtc()->addDays(20)]);
    }, 'billing::portal.coupon_recurring_needs_paid_subscription'],
    'a plan waiver is running' => [function () {
        portalRedeemRecurring('NOPE20');
        $billable = portalRedeemPaying();
        app(AdminExtensionService::class)->freePlanPeriods($billable, 2);
        PortalRedeemMollie::$sent = [];

        return $billable;
    }, 'billing::portal.coupon_recurring_conflict_plan_waiver'],
    'another recurring coupon is running' => [function () {
        portalRedeemRecurring('NOPE20');
        $billable = portalRedeemPaying();
        app(CouponService::class)->applyRecurringMarker(portalRedeemRecurring('FIRST10', ['discount_value' => 10]), $billable, 6500, 'monthly');

        return $billable;
    }, 'billing::checkout.coupon_recurring_conflict'],
    'the code is for another plan' => [function () {
        portalRedeemRecurring('NOPE20', ['applicable_plans' => ['starter']]);

        return portalRedeemPaying();
    }, 'billing::checkout.coupon_plan_mismatch'],
    'the code is for another interval' => [function () {
        portalRedeemRecurring('NOPE20', ['applicable_intervals' => ['yearly']]);

        return portalRedeemPaying();
    }, 'billing::checkout.coupon_interval_mismatch'],
    'a plan change is waiting for its payment' => [function () {
        portalRedeemRecurring('NOPE20');
        $billable = portalRedeemPaying();
        $meta = $billable->getBillingSubscriptionMeta();
        $meta['prorata_pending_payment_id'] = 'tr_pending';
        $billable->forceFill(['subscription_meta' => $meta])->save();

        return $billable;
    }, 'billing::portal.coupon_recurring_change_in_progress'],
]);

it('refuses the same recurring code a second time', function (): void {
    $billable = portalRedeemPaying();
    portalRedeemRecurring('ONCEONLY');
    $service = app(RedeemCouponFromPortal::class);

    expect($service->handle($billable, 'ONCEONLY')->redeemed)->toBeTrue();

    $second = $service->handle($billable->fresh(), 'ONCEONLY');

    expect($second->redeemed)->toBeFalse()
        ->and(CouponRedemption::query()->count())->toBe(1);
});

// ── A one-off discount ──────────────────────────────────────────────────────

it('says what a single-payment code is for instead of redeeming it', function (): void {
    $billable = portalRedeemPaying();
    $coupons = app(CouponService::class);
    $coupons->create(['code' => 'FIRST50', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 50]);
    $coupons->create([
        'code' => 'PACK20', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'applicable_products' => ['token-pack'],
    ]);

    $checkout = app(RedeemCouponFromPortal::class)->handle($billable, 'FIRST50');
    $product = app(RedeemCouponFromPortal::class)->handle($billable, 'PACK20');

    expect($checkout->redeemed)->toBeFalse()
        ->and($checkout->message)->toBe(__('billing::portal.coupon_single_payment_checkout'))
        ->and($product->redeemed)->toBeFalse()
        ->and($product->message)->toBe(__('billing::portal.coupon_single_payment_products', ['products' => 'Token pack']))
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(PortalRedeemMollie::$sent)->toBe([]);
});

it('still reports an expired single-payment code as expired', function (): void {
    $billable = portalRedeemPaying();
    app(CouponService::class)->create([
        'code' => 'OLD50', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 50,
    ])->forceFill(['valid_until' => BillingTime::nowUtc()->subDay()])->save();

    expect(app(RedeemCouponFromPortal::class)->handle($billable, 'OLD50')->message)
        ->toBe(__('billing::checkout.coupon_expired'));
});

it('says a single-payment code is used up instead of pointing at where to enter it', function (): void {
    $billable = portalRedeemPaying();
    $coupon = app(CouponService::class)->create([
        'code' => 'USEDPACK', 'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Percentage, 'discount_value' => 20,
        'applicable_products' => ['token-pack'],
    ]);
    app(CouponService::class)->redeem($coupon, $billable, ['productCodes' => ['token-pack'], 'orderAmountNet' => 1000]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable->fresh(), 'USEDPACK');

    expect($outcome->redeemed)->toBeFalse()
        ->and($outcome->message)->toBe(__('billing::checkout.coupon_per_billable_limit_reached'));
});

// ── The product as a gift ───────────────────────────────────────────────────

it('activates a gifted plan for a customer without a subscription', function (): void {
    $billable = portalRedeemNobody();
    app(CouponService::class)->accessGrantCoupon('GIFT30', 'pro', 'monthly', ['sso'], 30);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'gift30');

    $billable->refresh();

    expect($outcome->redeemed)->toBeTrue()
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Local->value)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($billable->getActiveBillingAddonCodes())->toBe(['sso'])
        ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe(BillingTime::nowUtc()->addDays(30)->toDateString())
        ->and($outcome->message)
        ->toContain('Pro')
        ->toContain(BillingTime::display($billable->getBillingSubscriptionEndsAt(), $billable)->translatedFormat('d. M Y'));
});

it('explains why a gift code cannot be added to a running subscription', function (Closure $arrange, string $messageKey): void {
    app(CouponService::class)->accessGrantCoupon('GIFT30', 'pro', 'monthly', [], 30);
    $billable = $arrange();
    $before = [$billable->getBillingSubscriptionSource(), $billable->getBillingSubscriptionPlanCode(), $billable->getBillingSubscriptionEndsAt()?->toIso8601String()];

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'GIFT30');
    $billable->refresh();

    expect($outcome->redeemed)->toBeFalse()
        ->and($outcome->message)->toBe(__($messageKey))
        ->and([$billable->getBillingSubscriptionSource(), $billable->getBillingSubscriptionPlanCode(), $billable->getBillingSubscriptionEndsAt()?->toIso8601String()])->toBe($before)
        ->and(CouponRedemption::query()->count())->toBe(0);
})->with([
    'a paid subscription' => [fn () => portalRedeemPaying(), 'billing::portal.coupon_grant_has_paid_subscription'],
    'a free subscription on another plan' => [fn () => portalRedeemPaying([
        'subscription_source' => SubscriptionSource::Local, 'subscription_plan_code' => 'starter',
        'active_addon_codes' => [], 'subscription_meta' => [],
    ]), 'billing::portal.coupon_grant_other_plan'],
]);

it('adds a second gift for the same plan to the end of the first', function (): void {
    $billable = portalRedeemNobody();
    $coupons = app(CouponService::class);
    $coupons->accessGrantCoupon('GIFTA', 'pro', 'monthly', [], 30);
    $coupons->accessGrantCoupon('GIFTB', 'pro', 'monthly', [], 20);

    app(RedeemCouponFromPortal::class)->handle($billable, 'GIFTA');
    $outcome = app(RedeemCouponFromPortal::class)->handle($billable->fresh(), 'GIFTB');

    expect($outcome->redeemed)->toBeTrue()
        ->and($billable->fresh()->getBillingSubscriptionEndsAt()->toDateString())->toBe(BillingTime::nowUtc()->addDays(50)->toDateString());
});

// ── What was already redeemed here ──────────────────────────────────────────

it('still redeems credits at once', function (): void {
    $billable = portalRedeemPaying();
    app(CouponService::class)->creditsCoupon('TOKENS', ['tokens' => 250]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'TOKENS');

    expect($outcome->redeemed)->toBeTrue()
        ->and($outcome->message)->toBe(__('billing::portal.coupon_redeem_success', ['code' => 'TOKENS']))
        ->and(CouponRedemption::query()->sole()->credits_applied)->toBe(['tokens' => 250]);
});

it('says that a trial code needs a trial', function (): void {
    $billable = portalRedeemPaying();
    app(CouponService::class)->trialExtensionCoupon('TRIAL7', 7);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'TRIAL7');

    expect($outcome->redeemed)->toBeFalse()
        ->and($outcome->message)->toBe(__('billing::portal.coupon_requires_running_trial'));
});

it('reports an unknown and an empty code', function (): void {
    $billable = portalRedeemPaying();

    expect(app(RedeemCouponFromPortal::class)->handle($billable, 'NOSUCHCODE')->message)->toBe(__('billing::checkout.coupon_not_found'))
        ->and(app(RedeemCouponFromPortal::class)->handle($billable, '   ')->message)->toBe(__('billing::checkout.coupon_empty'));
});

it('has every portal message in both languages', function (string $locale): void {
    app()->setLocale($locale);

    foreach ([
        'coupon_recurring_applied_periods', 'coupon_recurring_applied_until', 'coupon_recurring_applied_both',
        'coupon_recurring_applied_free', 'coupon_recurring_needs_paid_subscription', 'coupon_recurring_change_in_progress',
        'coupon_recurring_conflict_plan_waiver', 'coupon_recurring_ends_before_next_charge',
        'coupon_single_payment_checkout', 'coupon_single_payment_products',
        'coupon_grant_activated', 'coupon_grant_has_paid_subscription', 'coupon_grant_other_plan',
        'coupon_grant_needs_free_subscription', 'coupon_requires_running_trial',
    ] as $key) {
        expect(__('billing::portal.'.$key))->not->toContain('billing::');
    }
})->with(['en', 'de']);
