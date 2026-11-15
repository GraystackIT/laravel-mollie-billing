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
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The three free-time actions of the admin panel, in every state a subscription can be
 * in. Two questions per cell: does the date (or amount) move by exactly what was given,
 * and does a refusal leave nothing behind — no coupon, no half-written marker, no call
 * to Mollie.
 *
 * Pro 50.00 (2 seats included) + SSO 15.00, 20 % VAT. Free is a zero-price plan.
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
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => 0, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO',
        'feature_keys' => [],
        'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);

    ExtMatrixMollie::$sent = [];
    ExtMatrixMollie::$failNext = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if (ExtMatrixMollie::$failNext > 0) {
            ExtMatrixMollie::$failNext--;

            throw new RuntimeException('Mollie is unreachable');
        }

        ExtMatrixMollie::$sent[] = $request;

        return (object) ['id' => 'sub_ext_matrix'];
    });
});

final class ExtMatrixMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static int $failNext = 0;

    public static function lastPatch(): ?UpdateSubscriptionRequest
    {
        $patches = array_values(array_filter(self::$sent, fn ($r) => $r instanceof UpdateSubscriptionRequest));

        return $patches === [] ? null : end($patches);
    }

    public static function amount(?object $request): ?string
    {
        return $request !== null ? self::property($request, 'amount')?->value : null;
    }

    public static function startDate(?object $request): ?string
    {
        $date = $request !== null ? self::property($request, 'startDate') : null;

        return $date !== null ? (string) $date : null;
    }

    public static function property(object $request, string $name): mixed
    {
        $p = (new ReflectionClass($request))->getProperty($name);
        $p->setAccessible(true);

        return $p->getValue($request);
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function extMatrixBillable(string $state, array $overrides = []): TestBillable
{
    $now = BillingTime::nowUtc();

    $mollie = [
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $now->subDays(10),
        'active_addon_codes' => [],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_ext_matrix', 'seat_count' => 2],
        'mollie_customer_id' => 'cust_ext_matrix',
        'mollie_mandate_id' => 'mdt_ext_matrix',
    ];

    $local = [
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => $now->subDays(10),
        'subscription_meta' => [],
    ];

    $attributes = match ($state) {
        'none' => [],
        'new' => ['subscription_status' => SubscriptionStatus::New],
        'trial_mollie' => array_merge($mollie, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => $now->addDays(5),
            'subscription_period_starts_at' => $now->subDays(9),
        ]),
        'active_mollie' => $mollie,
        'active_mollie_addon' => array_merge($mollie, ['active_addon_codes' => ['sso']]),
        'past_due_mollie' => array_merge($mollie, [
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_period_starts_at' => $now->subDays(33),
            'subscription_meta' => $mollie['subscription_meta'] + ['past_due_since' => $now->subDays(2)->toIso8601String()],
        ]),
        'cancelled_mollie' => array_merge($mollie, [
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => $now->addDays(20),
        ]),
        'expired' => [
            'subscription_source' => SubscriptionSource::None,
            'subscription_status' => SubscriptionStatus::Expired,
            'subscription_period_starts_at' => $now->subDays(70),
        ],
        'local_free_perpetual' => $local,
        'local_grant' => array_merge($local, [
            'subscription_plan_code' => 'pro',
            'subscription_ends_at' => $now->addDays(20),
        ]),
        'local_cancelled' => array_merge($local, [
            'subscription_plan_code' => 'pro',
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => $now->addDays(20),
        ]),
    };

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Matrix '.$state, 'email' => uniqid('m-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge($attributes, $overrides))->save();

    return $billable->fresh();
}

/** Everything a refused action must leave exactly as it was. */
function extMatrixFingerprint(TestBillable $billable): array
{
    $billable = $billable->fresh();

    return [
        'status' => $billable->getBillingSubscriptionStatus()?->value,
        'source' => $billable->getBillingSubscriptionSource(),
        'ends_at' => $billable->getBillingSubscriptionEndsAt()?->toIso8601String(),
        'trial_ends_at' => $billable->getBillingTrialEndsAt()?->toIso8601String(),
        'period' => $billable->getBillingPeriodStartsAt()?->toIso8601String(),
        'meta' => $billable->getBillingSubscriptionMeta(),
        'coupons' => Coupon::query()->count(),
        'redemptions' => CouponRedemption::query()->count(),
        'mollie_calls' => count(ExtMatrixMollie::$sent),
    ];
}

// ── Refusals leave nothing ──────────────────────────────────────────────────

it('refuses "extend by days" where there is nothing to extend, and leaves nothing behind', function (string $state): void {
    $billable = extMatrixBillable($state);
    $before = extMatrixFingerprint($billable);

    expect(fn () => app(AdminExtensionService::class)->extendByDays($billable, 14))
        ->toThrow(InvalidCouponException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
})->with([
    'no subscription' => 'none',
    'new' => 'new',
    'expired' => 'expired',
    // A free plan without an end date has no date to move: writing one would make a
    // perpetual free plan expire in N days.
    'local free plan without an end date' => 'local_free_perpetual',
    // The Mollie subscription is cancelled; there is no charge left to push.
    'cancelled Mollie subscription in grace' => 'cancelled_mollie',
    // The failed renewal is the charge in question, and it is already overdue.
    'past due with the renewal overdue' => 'past_due_mollie',
]);

it('refuses free plan periods where they cannot apply, and leaves nothing behind', function (string $state): void {
    $billable = extMatrixBillable($state);
    $before = extMatrixFingerprint($billable);

    expect(fn () => app(AdminExtensionService::class)->freePlanPeriods($billable, 2))
        ->toThrow(InvalidCouponException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
})->with([
    'no subscription' => 'none',
    'new' => 'new',
    'expired' => 'expired',
    'local free plan without an end date' => 'local_free_perpetual',
    'past due' => 'past_due_mollie',
]);

it('refuses "extend the gift" where no gift is running, and leaves nothing behind', function (string $state): void {
    $billable = extMatrixBillable($state);
    $before = extMatrixFingerprint($billable);

    expect(fn () => app(AdminExtensionService::class)->extendGrant($billable, 30, PlanWaiver::ON_EXPIRY_CONTINUE_PAID))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
})->with([
    'no subscription' => 'none',
    'new' => 'new',
    'expired' => 'expired',
    'trial' => 'trial_mollie',
    'paying, no waiver' => 'active_mollie',
    'past due' => 'past_due_mollie',
    'cancelled Mollie subscription without a waiver' => 'cancelled_mollie',
    'local free plan without an end date' => 'local_free_perpetual',
    'local grant that was cancelled' => 'local_cancelled',
]);

it('rejects zero and negative amounts before anything is created', function (string $method): void {
    $billable = extMatrixBillable('active_mollie');
    $before = extMatrixFingerprint($billable);

    expect(fn () => app(AdminExtensionService::class)->{$method}($billable, 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AdminExtensionService::class)->{$method}($billable, -3))
        ->toThrow(InvalidArgumentException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
})->with(['extendByDays', 'freePlanPeriods', 'extendGrant']);

// ── Extend by days ──────────────────────────────────────────────────────────

it('extends a trial by days: the trial end and the first charge move together', function (): void {
    // A trial's first charge is its end, not the period anchor plus one interval. Read
    // off the anchor the charge moved to day 40 while the trial still ended on day 14 —
    // and a mandate-holding trial with no charge 14 days after its end is past due.
    $billable = extMatrixBillable('trial_mollie');
    $trialEnd = Carbon::instance($billable->getBillingTrialEndsAt());

    app(AdminExtensionService::class)->extendByDays($billable, 10);

    $billable->refresh();
    $expected = $trialEnd->copy()->addDays(10);

    expect($billable->getBillingTrialEndsAt()->toIso8601String())->toBe($expected->toIso8601String())
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($expected->toDateString())
        ->and($billable->getBillingSubscriptionEndsAt())->toBeNull();
});

it('judges "too close to the charge" by the trial end while a trial runs', function (): void {
    $billable = extMatrixBillable('trial_mollie', ['trial_ends_at' => BillingTime::nowUtc()->addHours(20)]);
    $before = extMatrixFingerprint($billable);

    try {
        app(AdminExtensionService::class)->extendByDays($billable, 10);
        $this->fail('An extension was accepted 20 hours before the trial-end charge.');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('too_close_to_charge');
    }

    expect(extMatrixFingerprint($billable))->toBe($before);
});

it('moves a paying subscription by exactly N days, and by 2N when given twice', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $before = Carbon::instance($billable->nextBillingDate());
    $service = app(AdminExtensionService::class);

    $service->extendByDays($billable, 14);
    expect($billable->fresh()->nextBillingDate()->toIso8601String())->toBe($before->copy()->addDays(14)->toIso8601String())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($before->copy()->addDays(14)->toDateString());

    $service->extendByDays($billable->fresh(), 14);
    $billable->refresh();

    expect($billable->nextBillingDate()->toIso8601String())->toBe($before->copy()->addDays(28)->toIso8601String())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($before->copy()->addDays(28)->toDateString())
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and(ChargeDeferral::nextChargeDate($billable)->toIso8601String())->toBe($before->copy()->addDays(28)->toIso8601String());
});

it('refuses an extension within 24 hours of the charge and accepts it just outside', function (): void {
    $inside = extMatrixBillable('active_mollie', [
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonthNoOverflow()->addHours(23),
    ]);
    $outside = extMatrixBillable('active_mollie', [
        'subscription_period_starts_at' => BillingTime::nowUtc()->subMonthNoOverflow()->addHours(25),
    ]);

    expect(fn () => app(AdminExtensionService::class)->extendByDays($inside, 7))
        ->toThrow(InvalidCouponException::class);

    $before = Carbon::instance($outside->nextBillingDate());
    app(AdminExtensionService::class)->extendByDays($outside, 7);

    expect($outside->fresh()->nextBillingDate()->toIso8601String())->toBe($before->addDays(7)->toIso8601String());
});

it('moves the charge under a partly covering plan waiver, and keeps the reduced amount', function (): void {
    $billable = extMatrixBillable('active_mollie_addon');
    $service = app(AdminExtensionService::class);
    $service->freePlanPeriods($billable, 2);

    $before = Carbon::instance($billable->fresh()->nextBillingDate());
    ExtMatrixMollie::$sent = [];

    $service->extendByDays($billable->fresh(), 10);
    $billable->refresh();

    // The date moves; the amount is not touched by a date-only PATCH.
    expect($billable->nextBillingDate()->toIso8601String())->toBe($before->copy()->addDays(10)->toIso8601String())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($before->copy()->addDays(10)->toDateString())
        ->and(ExtMatrixMollie::property(ExtMatrixMollie::lastPatch(), 'amount'))->toBeNull()
        ->and(app(CouponService::class)->computeMarkerDiscount($billable, 6500))->toBe(5000);
});

it('appends the days to the deferral under a fully covering plan waiver', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $service = app(AdminExtensionService::class);
    $service->freePlanPeriods($billable, 2);

    $billable->refresh();
    $deferral = ChargeDeferral::until($billable);
    $periodEnd = Carbon::instance($billable->nextBillingDate());

    $service->extendByDays($billable, 10);
    $billable->refresh();

    // The charge moves, never forward; the period keeps turning over on its own cycle.
    expect(ChargeDeferral::until($billable)->toIso8601String())->toBe($deferral->copy()->addDays(10)->toIso8601String())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($deferral->copy()->addDays(10)->toDateString())
        ->and($billable->nextBillingDate()->toIso8601String())->toBe($periodEnd->toIso8601String());

    // And a later change of the amount does not take the days back.
    app(PlanWaiver::class)->syncMollie($billable);

    expect(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($deferral->copy()->addDays(10)->toDateString());
});

it('appends the days to the deferral under a 100% recurring coupon', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FREE3',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $billable->refresh();
    $deferral = ChargeDeferral::until($billable);
    expect($deferral)->not->toBeNull();

    app(AdminExtensionService::class)->extendByDays($billable, 10);
    app(AdminExtensionService::class)->extendByDays($billable->fresh(), 5);

    expect(ChargeDeferral::until($billable->fresh())->toIso8601String())
        ->toBe($deferral->copy()->addDays(15)->toIso8601String());
});

it('extends a Local grant and a cancelled Local grant by exactly N days', function (string $state): void {
    $billable = extMatrixBillable($state);
    $before = Carbon::instance($billable->getBillingSubscriptionEndsAt());

    app(AdminExtensionService::class)->extendByDays($billable, 14);
    app(AdminExtensionService::class)->extendByDays($billable->fresh(), 14);

    expect($billable->fresh()->getBillingSubscriptionEndsAt()->toIso8601String())
        ->toBe($before->addDays(28)->toIso8601String())
        ->and(ExtMatrixMollie::$sent)->toBe([]);
})->with(['local_grant', 'local_cancelled']);

it('queues the date PATCH when Mollie is unreachable and lands it on retry', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $before = Carbon::instance($billable->nextBillingDate());

    ExtMatrixMollie::$failNext = 1;
    app(AdminExtensionService::class)->extendByDays($billable, 14);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    expect($pending['reason'] ?? null)->toBe('next_charge_date')
        ->and(ExtMatrixMollie::$sent)->toBe([]);

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    $billable->refresh();

    expect(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($before->copy()->addDays(14)->toDateString())
        ->and($billable->nextBillingDate()->toIso8601String())->toBe($before->copy()->addDays(14)->toIso8601String())
        ->and($billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null)->toBeNull();
});

// ── Free plan periods ───────────────────────────────────────────────────────

it('gives a trial free plan periods counted from the trial end', function (): void {
    $billable = extMatrixBillable('trial_mollie');
    $trialEnd = Carbon::instance($billable->getBillingTrialEndsAt());

    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $billable->refresh();

    // Nothing but the plan is billed: full price, first charge two periods after the
    // trial would have ended.
    expect(ChargeDeferral::until($billable)->toDateString())->toBe($trialEnd->copy()->addMonthsNoOverflow(2)->toDateString())
        ->and(ExtMatrixMollie::amount(ExtMatrixMollie::lastPatch()))->toBe('60.00');
});

it('adds whole billing periods to a Local grant', function (): void {
    $billable = extMatrixBillable('local_grant');
    $before = Carbon::instance($billable->getBillingSubscriptionEndsAt());

    $redemption = app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    expect($billable->fresh()->getBillingSubscriptionEndsAt()->toIso8601String())
        ->toBe($before->copy()->addMonthsNoOverflow(2)->toIso8601String())
        ->and(app(PlanWaiver::class)->marker($billable->fresh()))->toBeNull();

    // … and takes exactly those days off again on revoke.
    app(CouponService::class)->revokeGrant($redemption, 'audit');

    expect($billable->fresh()->getBillingSubscriptionEndsAt()->toDateString())->toBe($before->toDateString());
});

// ── Extend the gift ─────────────────────────────────────────────────────────

it('extends a Local gift whose end has just passed from now, not from the stale date', function (): void {
    $billable = extMatrixBillable('local_grant', ['subscription_ends_at' => BillingTime::nowUtc()->subHours(3)]);

    $newEnd = app(AdminExtensionService::class)->extendGrant($billable, 10);

    expect($newEnd->toDateString())->toBe(BillingTime::nowUtc()->addDays(10)->toDateString())
        ->and($billable->fresh()->hasAccessibleBillingSubscription())->toBeTrue();
});

it('does not extend a gift twice when bringing the subscription back fails', function (): void {
    // The gift is extended first and the subscription recreated after. With the first
    // kept when the second throws, the operator's retry extended it a second time.
    $billable = extMatrixBillable('active_mollie_addon');
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-MATRIX', 'pro', 'monthly', [], 5);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(5), null, PlanWaiver::ON_EXPIRY_END);
    app(CancelSubscription::class)->handle($billable->fresh(), false, 'grant_expired');

    $before = app(PlanWaiver::class)->marker($billable->fresh())['valid_until'];

    ExtMatrixMollie::$failNext = 1;

    expect(fn () => app(AdminExtensionService::class)->extendGrant($billable->fresh(), 60))
        ->toThrow(RuntimeException::class);

    expect(app(PlanWaiver::class)->marker($billable->fresh())['valid_until'])->toBe($before)
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);

    // The retry then gives exactly the 60 days, once.
    app(AdminExtensionService::class)->extendGrant($billable->fresh(), 60);

    expect(Carbon::parse((string) app(PlanWaiver::class)->marker($billable->fresh())['valid_until'])->toIso8601String())
        ->toBe(Carbon::parse((string) $before)->addDays(60)->toIso8601String())
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('brings a cancelled gift back on the period it had: amount, first charge and anchor', function (): void {
    $billable = extMatrixBillable('active_mollie_addon');
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-BACK', 'pro', 'monthly', [], 5);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(5), null, PlanWaiver::ON_EXPIRY_END);
    app(CancelSubscription::class)->handle($billable->fresh(), false, 'grant_expired');

    $graceEnd = Carbon::instance($billable->fresh()->getBillingSubscriptionEndsAt());
    ExtMatrixMollie::$sent = [];

    app(AdminExtensionService::class)->extendGrant($billable->fresh(), 60);

    $billable->refresh();
    $created = collect(ExtMatrixMollie::$sent)->first(fn ($r) => $r instanceof CreateSubscriptionRequest);
    $marker = app(PlanWaiver::class)->marker($billable);

    expect(ExtMatrixMollie::amount($created))->toBe('18.00')
        ->and(ExtMatrixMollie::startDate($created))->toBe($graceEnd->toDateString())
        ->and($billable->nextBillingDate()->toDateString())->toBe($graceEnd->toDateString())
        ->and($billable->getBillingSubscriptionEndsAt())->toBeNull()
        ->and($marker['on_expiry'])->toBe(PlanWaiver::ON_EXPIRY_END)
        ->and(Carbon::parse((string) $marker['valid_until'])->toDateString())->toBe(BillingTime::nowUtc()->addDays(65)->toDateString())
        // The next renewal is still covered, so nothing ends the subscription again yet.
        ->and(app(PlanWaiver::class)->endsSubscription($billable, $billable->nextBillingDate()))->toBeFalse();
});

it('keeps Mollie in step when extending a fully covering gift while Mollie is unreachable', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $renewal = Carbon::instance($billable->nextBillingDate());
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-DEFER', 'pro', 'monthly', [], 40, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(40), null, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $deferral = ChargeDeferral::until($billable->fresh());

    // Mollie holds the full price behind the old date. An extension whose PATCH is
    // simply lost would have it collected while the gift is still running.
    ExtMatrixMollie::$failNext = 1;
    app(AdminExtensionService::class)->extendGrant($billable->fresh(), 30);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;
    expect($pending)->toBeArray();

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    // 40 days reached the renewal in ~51 days; 70 reach the one after it.
    $expected = $renewal->copy()->addMonthsNoOverflow(2)->toDateString();

    expect($deferral->toDateString())->toBe($renewal->copy()->addMonthsNoOverflow(1)->toDateString())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($expected)
        ->and(ChargeDeferral::until($billable->fresh())->toDateString())->toBe($expected);
});

it('leaves a gift that was set to end cancelled when the extension still does not reach the next charge', function (): void {
    // Gift ends in 5 days, the paid period in ~20: the subscription was cancelled with
    // it. Three more days change nothing about that — brought back, the renewal in 20
    // days would either be collected for a plan the tenant never agreed to pay for, or
    // (with extras) waive a period the gift no longer covers.
    $billable = extMatrixBillable('active_mollie_addon');
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-SHORT', 'pro', 'monthly', [], 5);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(5), null, PlanWaiver::ON_EXPIRY_END);
    app(CancelSubscription::class)->handle($billable->fresh(), false, 'grant_expired');
    $graceEnd = $billable->fresh()->getBillingSubscriptionEndsAt()->toIso8601String();

    ExtMatrixMollie::$sent = [];
    $newEnd = app(AdminExtensionService::class)->extendGrant($billable->fresh(), 3);

    $billable->refresh();

    expect($newEnd->toDateString())->toBe(BillingTime::nowUtc()->addDays(8)->toDateString())
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->toIso8601String())->toBe($graceEnd)
        ->and(collect(ExtMatrixMollie::$sent)->filter(fn ($r) => $r instanceof CreateSubscriptionRequest)->all())->toBe([]);
});

it('ends the subscription at once when a gift is switched to "end" and does not reach the next charge', function (): void {
    // Plan-only subscription, gift given to continue as a paid plan and ending inside
    // the running period: Mollie holds the full price for the renewal. Switched to
    // "end", that renewal must not be collected — nothing else looks at this
    // subscription before it is.
    $billable = extMatrixBillable('active_mollie');
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-FLIP', 'pro', 'monthly', [], 5, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(5), null, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $renewal = Carbon::instance($billable->fresh()->nextBillingDate());

    app(AdminExtensionService::class)->extendGrant($billable->fresh(), 2, PlanWaiver::ON_EXPIRY_END);

    $billable->refresh();
    $cancelled = collect(ExtMatrixMollie::$sent)->filter(fn ($r) => $r instanceof \Mollie\Api\Http\Requests\CancelSubscriptionRequest);

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()->toDateString())->toBe($renewal->toDateString())
        ->and($cancelled)->toHaveCount(1);
});

// ── Trial extension on a subscription that is already paying ────────────────

it('never moves a paying subscription\'s charge earlier with a trial extension', function (): void {
    // extendBillingTrialUntil() accepts Active, and sets Mollie's start date to the new
    // trial end. Paid until day 20 and "trial until day 5" charged the customer the
    // next period fifteen days early.
    $billable = extMatrixBillable('active_mollie');
    $before = extMatrixFingerprint($billable);

    expect(fn () => $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(5)))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
});

it('never moves a deferred charge earlier with a trial extension either', function (): void {
    $billable = extMatrixBillable('active_mollie');
    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);
    $billable->refresh();
    $deferral = ChargeDeferral::until($billable);
    ExtMatrixMollie::$sent = [];

    // Past the period end, but inside the two free periods.
    expect(fn () => $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(40)))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(ExtMatrixMollie::$sent)->toBe([])
        ->and(ChargeDeferral::until($billable->fresh())->toIso8601String())->toBe($deferral->toIso8601String())
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('extends a paying subscription into a trial that ends after the paid period', function (): void {
    $billable = extMatrixBillable('active_mollie');
    $until = BillingTime::nowUtc()->addDays(40);

    $billable->extendBillingTrialUntil($until);
    $billable->refresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Trial)
        ->and($billable->getBillingTrialEndsAt()->toDateString())->toBe($until->toDateString())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($until->toDateString())
        ->and($billable->nextBillingDate()->toDateString())->toBe($until->toDateString());
});

it('extends a running trial by date, later only', function (): void {
    $billable = extMatrixBillable('trial_mollie');
    $trialEnd = Carbon::instance($billable->getBillingTrialEndsAt());

    // Earlier than the current end: nothing moves.
    $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(2));
    expect($billable->fresh()->getBillingTrialEndsAt()->toIso8601String())->toBe($trialEnd->toIso8601String())
        ->and(ExtMatrixMollie::$sent)->toBe([]);

    $until = BillingTime::nowUtc()->addDays(12);
    $billable->fresh()->extendBillingTrialUntil($until);

    expect($billable->fresh()->getBillingTrialEndsAt()->toDateString())->toBe($until->toDateString())
        ->and(ExtMatrixMollie::startDate(ExtMatrixMollie::lastPatch()))->toBe($until->toDateString());
});

it('refuses a trial extension on a cancelled or expired subscription', function (string $state): void {
    $billable = extMatrixBillable($state);
    $before = extMatrixFingerprint($billable);

    expect(fn () => $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(10)))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(extMatrixFingerprint($billable))->toBe($before);
})->with(['cancelled_mollie', 'local_cancelled', 'expired']);

// ── What the subscription tab offers ────────────────────────────────────────

it('offers "extend the gift" for a gift, and not for free plan periods on a paying subscription', function (): void {
    $title = __('billing::admin.billables.subscription.free_time.extend_grant_title');

    // Free plan periods on a paying customer: nothing to extend by date.
    $paying = extMatrixBillable('active_mollie_addon');
    app(AdminExtensionService::class)->freePlanPeriods($paying, 2);

    \Livewire\Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $paying->getKey()])
        ->assertDontSee($title)
        ->set('grantDays', '30')
        ->set('grantOnExpiry', 'end')
        ->call('extendGrant')
        ->assertSet('flash', null);

    expect(app(PlanWaiver::class)->marker($paying->fresh())['on_expiry'])->toBe(PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    // A gift that lives on as a waiver.
    $gifted = extMatrixBillable('active_mollie_addon');
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-TAB', 'pro', 'monthly', [], 60);
    app(PlanWaiver::class)->grant($gifted, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(60), null, PlanWaiver::ON_EXPIRY_END);

    \Livewire\Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $gifted->getKey()])
        ->assertSee($title);
});

it('shows a refused extension as a message and changes nothing', function (): void {
    $billable = extMatrixBillable('local_free_perpetual');
    $before = extMatrixFingerprint($billable);

    \Livewire\Livewire::test('mollie-billing::admin.billables.subscription-tab', ['billableId' => $billable->getKey()])
        ->set('extendDays', '14')
        ->call('extendSubscription')
        ->assertSet('flash', null)
        ->assertNotSet('error', null);

    expect(extMatrixFingerprint($billable))->toBe($before);
});
