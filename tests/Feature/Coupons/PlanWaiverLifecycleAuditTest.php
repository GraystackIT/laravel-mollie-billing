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
use GraystackIT\MollieBilling\Models\BillingInvoice;
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
 * A plan waiver through the things that happen to it after it was given: a Mollie PATCH
 * that does not land, the coupon behind it being switched off, the same code redeemed
 * again, an operator extending or revoking it, the subscription being cancelled.
 *
 * Pro 50.00 (2 seats included, 5.00 per extra seat) + SSO 15.00, 20 % VAT.
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

    WaiverAuditMollie::$sent = [];
    WaiverAuditMollie::$failNext = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if (WaiverAuditMollie::$failNext > 0) {
            WaiverAuditMollie::$failNext--;

            throw new RuntimeException('Mollie is unreachable');
        }

        WaiverAuditMollie::$sent[] = $request;

        return (object) ['id' => 'sub_waiver_audit'];
    });
});

final class WaiverAuditMollie
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

function waiverAuditPaying(array $addons = [], int $seats = 2, int $daysIntoPeriod = 10): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Waiver Co', 'email' => uniqid('wv-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysIntoPeriod),
        'active_addon_codes' => $addons,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_waiver_audit', 'seat_count' => $seats],
        'mollie_customer_id' => 'cust_waiver_audit',
        'mollie_mandate_id' => 'mdt_waiver_audit',
    ])->save();

    return $billable->fresh();
}

/** A gift carried over to a Mollie subscription: plan free until `$days` from now. */
function waiverAuditGift(TestBillable $billable, int $days, string $onExpiry = PlanWaiver::ON_EXPIRY_END): Coupon
{
    $coupon = app(CouponService::class)->accessGrantCoupon(
        'GIFT-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], $days, $onExpiry,
    );

    app(PlanWaiver::class)->grant(
        $billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays($days), null, $onExpiry,
    );

    return $coupon;
}

function waiverAuditInvoice(TestBillable $billable): BillingInvoice
{
    return BillingInvoice::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'mollie_payment_id' => 'tr_'.bin2hex(random_bytes(5)),
        'serial_number' => 'WV-'.bin2hex(random_bytes(4)),
        'invoice_kind' => 'subscription',
        'status' => 'paid',
        'country' => 'AT',
        'currency' => 'EUR',
        'amount_net' => 1500,
        'amount_vat' => 300,
        'amount_gross' => 1800,
        'line_items' => [],
    ]);
}

// ── A PATCH that does not land ──────────────────────────────────────────────

it('keeps free plan periods when the Mollie PATCH fails and is retried', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);

    // The redemption commits, the PATCH that follows it throws.
    WaiverAuditMollie::$failNext = 1;
    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    expect($pending)->toBeArray()
        ->and(app(PlanWaiver::class)->marker($billable))->not->toBeNull();

    // What the hourly sweep dispatches for that marker.
    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    $billable->refresh();

    // The gift is still there, and Mollie collects the extras only: SSO 15.00 + 20 %.
    expect(app(PlanWaiver::class)->marker($billable))->not->toBeNull()
        ->and(app(CouponService::class)->computeMarkerDiscount($billable, 6500))->toBe(5000)
        ->and(WaiverAuditMollie::amount(WaiverAuditMollie::lastPatch()))->toBe('18.00')
        ->and($billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null)->toBeNull();
});

it('moves the deferred charge on retry when appended free periods could not be PATCHed', function (): void {
    $billable = waiverAuditPaying();
    $service = app(AdminExtensionService::class);

    $service->freePlanPeriods($billable, 1);
    $firstDeferral = ChargeDeferral::until($billable->fresh());

    $this->travel(AdminExtensionService::REPEAT_GUARD_SECONDS + 1)->seconds();
    WaiverAuditMollie::$failNext = 1;
    $service->freePlanPeriods($billable->fresh(), 1);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;
    expect($pending)->toBeArray();

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    $billable->refresh();
    $expected = Carbon::instance(BillingTime::addInterval($firstDeferral, 'monthly'))->toDateString();

    // Mollie holds the full price behind the start date; left on the old date it would
    // collect it a period before the waiver ends.
    expect(app(PlanWaiver::class)->marker($billable))->not->toBeNull()
        ->and(WaiverAuditMollie::startDate(WaiverAuditMollie::lastPatch()))->toBe($expected)
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected);
});

it('returns Mollie to the full price on retry when a revoked waiver could not be PATCHed', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    $redemption = app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    WaiverAuditMollie::$failNext = 1;
    app(CouponService::class)->revokeGrant($redemption, 'mistake');

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;
    expect($pending)->toBeArray();

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    expect(app(PlanWaiver::class)->marker($billable->fresh()))->toBeNull()
        ->and(WaiverAuditMollie::amount(WaiverAuditMollie::lastPatch()))->toBe('78.00');
});

// ── The coupon behind it ────────────────────────────────────────────────────

it('keeps a gift running when the grant code behind it is deactivated', function (): void {
    // Switching a grant code off stops NEW redemptions. A tenant on a Local grant keeps
    // the time they were given; one who bought extras on top must not lose it either —
    // with `on_expiry = end` the next renewal would cancel their subscription.
    $billable = waiverAuditPaying(addons: ['sso']);
    $coupon = waiverAuditGift($billable, 90);

    app(CouponService::class)->deactivate($coupon);

    $billable->refresh();
    $coupons = app(CouponService::class);

    expect($coupons->computeMarkerDiscount($billable, 6500))->toBe(5000)
        ->and($coupons->markerExpired($billable, $billable->nextBillingDate()))->toBeFalse()
        ->and(app(PlanWaiver::class)->endsSubscription($billable, $billable->nextBillingDate()))->toBeFalse();
});

it('still ends an ordinary recurring coupon when it is deactivated', function (): void {
    $billable = waiverAuditPaying();
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'REC50AUDIT',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 6,
    ]);
    $coupons->applyRecurringMarker($coupon, $billable, 5000, 'monthly');

    $coupons->deactivate($coupon);

    expect($coupons->computeMarkerDiscount($billable->fresh(), 5000))->toBe(0)
        ->and($coupons->markerExpired($billable->fresh()))->toBeTrue();
});

it('counts the waived renewals of this waiver, not of an earlier one from the same code', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'TWICEFREE',
        'type' => CouponType::PlanWaiver,
        'waiver_periods' => 1,
        'max_redemptions_per_billable' => 2,
    ]);

    // First redemption: one waived renewal, then the waiver is over and cleared — what
    // the renewal handler does once the count is reached.
    $coupons->redeem($coupon, $billable, []);
    $coupons->redeemRecurringCouponForRenewal($billable->fresh(), 6500, waiverAuditInvoice($billable)->id);
    expect($coupons->markerExpired($billable->fresh()))->toBeTrue();
    $coupons->clearActiveRecurringCouponMarker($billable->fresh());

    // Second redemption of the same code, a period later.
    Carbon::setTestNow(BillingTime::nowUtc()->addDays(31));
    $billable->forceFill(['subscription_period_starts_at' => BillingTime::nowUtc()->subDay()])->save();

    try {
        $coupons->validate('TWICEFREE', $billable->fresh(), []);
        $coupons->redeem($coupon, $billable->fresh(), []);

        // It was accepted and it consumed the second slot — so it has to waive a renewal.
        expect($coupons->computeMarkerDiscount($billable->fresh(), 6500))->toBe(5000);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not let a renewal that waived nothing use up a free period', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    app(AdminExtensionService::class)->freePlanPeriods($billable, 1);
    $billable->refresh();

    $marker = app(PlanWaiver::class)->marker($billable);

    CouponRedemption::create([
        'coupon_id' => $marker['coupon_id'],
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'applied_at' => BillingTime::nowUtc(),
        'discount_amount_net' => 0,
        'grant_applied_snapshot' => ['mode' => 'plan_waiver_renewal'],
    ]);

    expect(app(CouponService::class)->computeMarkerDiscount($billable, 6500))->toBe(5000);
});

// ── Operator actions ────────────────────────────────────────────────────────

it('does not treat free plan periods on a paying subscription as a gift to extend', function (): void {
    // "Extend the gift" moves a date and can set `on_expiry = end`. Free periods are a
    // count on a customer who pays: moving the date changed nothing the count did not
    // already decide, and `end` would cancel a paying customer's subscription when the
    // free periods ran out.
    $billable = waiverAuditPaying(addons: ['sso']);
    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);
    $before = app(PlanWaiver::class)->marker($billable->fresh());

    expect(fn () => app(AdminExtensionService::class)->extendGrant($billable->fresh(), 30, PlanWaiver::ON_EXPIRY_END))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(app(PlanWaiver::class)->marker($billable->fresh()))->toBe($before);
});

it('extends a deferred gift: the gift ends exactly that much later, the charge on the renewal after it', function (): void {
    // Renewals in ~20, ~51 and ~81 days. A gift of 40 days resumes on the second.
    $billable = waiverAuditPaying();
    $renewal = Carbon::instance($billable->nextBillingDate());
    waiverAuditGift($billable, 40, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $giftEnd = Carbon::parse((string) app(PlanWaiver::class)->marker($billable->fresh())['valid_until']);
    expect(ChargeDeferral::until($billable->fresh())?->toDateString())
        ->toBe($renewal->copy()->addMonthsNoOverflow(1)->toDateString());

    // 25 days more: ends on day 65, so the renewal on day ~51 is free as well.
    $newEnd = app(AdminExtensionService::class)->extendGrant($billable->fresh(), 25);

    $billable->refresh();
    $expected = $renewal->copy()->addMonthsNoOverflow(2)->toDateString();

    expect($newEnd->toIso8601String())->toBe($giftEnd->copy()->addDays(25)->toIso8601String())
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected)
        ->and(WaiverAuditMollie::startDate(WaiverAuditMollie::lastPatch()))->toBe($expected)
        ->and(WaiverAuditMollie::amount(WaiverAuditMollie::lastPatch()))->toBe('60.00');
});

it('revokes a deferred gift: the full price returns on the regular date, not at once', function (): void {
    $billable = waiverAuditPaying();
    $coupon = waiverAuditGift($billable, 90);
    app(PlanWaiver::class)->syncMollie($billable->fresh());

    $redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'applied_at' => BillingTime::nowUtc(),
        'discount_amount_net' => 0,
        'grant_days_added' => 90,
        'grant_applied_snapshot' => ['mode' => 'full', 'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'duration_days' => 90],
    ]);

    $regular = $billable->fresh()->nextBillingDate()->toDateString();

    app(CouponService::class)->revokeGrant($redemption, 'audit');

    $billable->refresh();

    expect(app(PlanWaiver::class)->marker($billable))->toBeNull()
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and(WaiverAuditMollie::amount(WaiverAuditMollie::lastPatch()))->toBe('60.00')
        ->and(WaiverAuditMollie::startDate(WaiverAuditMollie::lastPatch()))->toBe($regular)
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
});

it('revokes a gift on an already cancelled subscription without calling Mollie', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    $coupon = waiverAuditGift($billable, 5);
    app(CancelSubscription::class)->handle($billable->fresh(), false, 'grant_expired');

    $redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'applied_at' => BillingTime::nowUtc(),
        'discount_amount_net' => 0,
        'grant_days_added' => 5,
        'grant_applied_snapshot' => ['mode' => 'full', 'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'duration_days' => 5],
    ]);

    WaiverAuditMollie::$sent = [];
    $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt();

    app(CouponService::class)->revokeGrant($redemption, 'audit');

    $billable->refresh();

    // The grace period that was paid for stands; nothing is left to re-price.
    expect(app(PlanWaiver::class)->marker($billable))->toBeNull()
        ->and($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        ->and($billable->getBillingSubscriptionEndsAt()?->toIso8601String())->toBe($endsAt?->toIso8601String())
        ->and(WaiverAuditMollie::$sent)->toBe([])
        ->and($billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null)->toBeNull();
});

it('leaves a paying subscription alone when a superseded grant is revoked', function (): void {
    // Mollie, no waiver: the grant was given up for a paid plan.
    $billable = waiverAuditPaying();
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-OLD', 'pro', 'monthly', [], 30);
    $redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'applied_at' => BillingTime::nowUtc()->subDays(40),
        'discount_amount_net' => 0,
        'grant_days_added' => 30,
        'grant_applied_snapshot' => ['mode' => 'full', 'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'duration_days' => 30],
    ]);

    app(CouponService::class)->revokeGrant($redemption, 'audit');

    $billable->refresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionSource())->toBe(SubscriptionSource::Mollie->value)
        ->and($billable->getBillingSubscriptionPlanCode())->toBe('pro')
        ->and($redemption->fresh()->isRevoked())->toBeTrue()
        ->and(WaiverAuditMollie::$sent)->toBe([]);
});

// ── The single slot ─────────────────────────────────────────────────────────

it('refuses a recurring coupon while a waiver holds the slot, and says why', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    app(AdminExtensionService::class)->freePlanPeriods($billable, 2);

    $coupons = app(CouponService::class);
    $coupons->create([
        'code' => 'REC10AUDIT',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
        'max_redemptions_per_billable' => 3,
    ]);

    try {
        $coupons->validate('REC10AUDIT', $billable->fresh(), [
            'planCode' => 'pro',
            'interval' => 'monthly',
            'orderAmountNet' => 6500,
            'allowed_types' => [CouponType::Recurring],
        ]);
        $this->fail('A recurring coupon was accepted next to a plan waiver.');
    } catch (InvalidCouponException $e) {
        expect($e->reason())->toBe('recurring_conflict');
    }

    // And the waiver is untouched.
    expect(app(PlanWaiver::class)->marker($billable->fresh())['kind'])->toBe(PlanWaiver::KIND);
});

// ── Boundaries ──────────────────────────────────────────────────────────────

it('ends the subscription exactly when the gift no longer reaches the next charge', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    waiverAuditGift($billable, 30);
    $billable->refresh();

    $end = Carbon::parse((string) app(PlanWaiver::class)->marker($billable)['valid_until']);
    $waiver = app(PlanWaiver::class);

    // "A renewal is plan-free when it falls before the gift's end" — before the gift's end
    // DAY. A gift has no partial days: a charge on the last day, at whatever hour (Mollie
    // creates its charges early in the morning, the gift may have been given at noon), is
    // the first one the gift does not cover. See PlanWaiver::endDay().
    $endDay = $end->copy()->utc()->startOfDay();

    expect($waiver->endsSubscription($billable, $endDay->copy()->subSecond()))->toBeFalse()
        ->and($waiver->endsSubscription($billable, $endDay->copy()))->toBeTrue()
        ->and($waiver->endsSubscription($billable, $end->copy()->subSecond()))->toBeTrue()
        ->and($waiver->endsSubscription($billable, $end->copy()))->toBeTrue()
        ->and($waiver->endsSubscription($billable, $end->copy()->addSecond()))->toBeTrue();
});

it('never ends a subscription for a waiver given to continue as a paid plan', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    waiverAuditGift($billable, 30, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    expect(app(PlanWaiver::class)->endsSubscription($billable->fresh(), BillingTime::nowUtc()->addDays(60)))->toBeFalse();
});

it('makes no Mollie call when there is no Mollie subscription to bring in line', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    waiverAuditGift($billable, 30);

    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    unset($meta['mollie_subscription_id']);
    $billable->forceFill(['subscription_meta' => $meta])->save();

    app(PlanWaiver::class)->syncMollie($billable->fresh());

    expect(WaiverAuditMollie::$sent)->toBe([]);
});

it('carries the waiver through a cancellation and a resubscribe at the reduced amount', function (): void {
    $billable = waiverAuditPaying(addons: ['sso']);
    waiverAuditGift($billable, 120, PlanWaiver::ON_EXPIRY_CONTINUE_PAID);

    app(CancelSubscription::class)->handle($billable->fresh(), false, 'customer');
    $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt();

    expect(app(PlanWaiver::class)->marker($billable->fresh()))->not->toBeNull();

    WaiverAuditMollie::$sent = [];
    app(\GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription::class)->handle($billable->fresh());

    $created = collect(WaiverAuditMollie::$sent)->first(fn ($r) => $r instanceof CreateSubscriptionRequest);

    // Extras only, and from the end of the period that was already paid for.
    expect($created)->not->toBeNull()
        ->and(WaiverAuditMollie::amount($created))->toBe('18.00')
        ->and(WaiverAuditMollie::startDate($created))->toBe($endsAt->toDateString())
        ->and(ChargeDeferral::until($billable->fresh()))->toBeNull();
});
