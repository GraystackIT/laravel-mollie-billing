<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionPatchJob;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Services\Billing\PlanChangeIntent;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * `charge_deferred_until` is a statement about what Mollie holds. These tests follow it
 * through everything that can happen to a subscription while its charge is deferred, and
 * ask one question each time: does the stored date still agree with the start date the
 * package last sent to Mollie?
 *
 * Basic 10.00 (1 seat included, 5.00 per extra seat), Plus 30.00, 20 % VAT.
 */

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);

    foreach (['basic' => 1000, 'plus' => 3000] as $code => $price) {
        config()->set("mollie-billing-plans.plans.{$code}", [
            'name' => ucfirst($code),
            'tier' => $code === 'basic' ? 1 : 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => $price, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => $price * 10, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ]);
    }

    DeferralLifecycleMollie::$sent = [];
    DeferralLifecycleMollie::$failNext = 0;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if (DeferralLifecycleMollie::$failNext > 0) {
            DeferralLifecycleMollie::$failNext--;

            throw new RuntimeException('Mollie is unreachable');
        }

        DeferralLifecycleMollie::$sent[] = $request;

        return (object) ['id' => 'sub_deferral_lifecycle'];
    });
});

final class DeferralLifecycleMollie
{
    /** @var list<object> */
    public static array $sent = [];

    public static int $failNext = 0;

    public static function last(string $class = UpdateSubscriptionRequest::class): ?object
    {
        $matching = array_values(array_filter(self::$sent, fn ($r) => $r instanceof $class));

        return $matching === [] ? null : end($matching);
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
 * A subscription on Basic under a 100 % coupon for three periods, the charge deferred —
 * as CreateSubscription leaves it, or (`$stored = false`) as a release before the date
 * was stored left it: deferred at Mollie, nothing on the row.
 *
 * @return array{0: TestBillable, 1: Coupon}
 */
function deferralLifecycleCovered(bool $stored = true, int $daysIntoPeriod = 10): array
{
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'FREE3-'.strtoupper(bin2hex(random_bytes(2))),
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Deferred', 'email' => uniqid('df-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'basic',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysIntoPeriod),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_deferral_lifecycle', 'seat_count' => 1],
        'mollie_customer_id' => 'cust_deferral_lifecycle',
        'mollie_mandate_id' => 'mdt_deferral_lifecycle',
    ])->save();

    $coupons->applyRecurringMarker($coupon, $billable->fresh(), 1000, 'monthly');

    if ($stored) {
        $billable = $billable->fresh();
        $meta = $billable->getBillingSubscriptionMeta();
        $meta[ChargeDeferral::META_KEY] = Carbon::parse((string) $meta['active_recurring_coupon']['valid_until'])
            ->addDay()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    }

    return [$billable->fresh(), $coupon];
}

function deferralLifecycleIntent(TestBillable $billable, string $plan, string $interval, int $seats): PlanChangeIntent
{
    return PlanChangeIntent::fromArray([
        'billable_type' => $billable::class,
        'billable_id' => $billable->getKey(),
        'current_plan' => (string) $billable->getBillingSubscriptionPlanCode(),
        'current_interval' => (string) $billable->getBillingSubscriptionInterval(),
        'new_plan' => $plan,
        'new_interval' => $interval,
        'current_seats' => $billable->getBillingSeatCount(),
        'new_seats' => $seats,
        'current_addons' => [],
        'new_addons' => [],
    ]);
}

// ── Plan, seats, interval ───────────────────────────────────────────────────

it('returns the charge to the regular date on an upgrade and defers it again on the way back', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);
    $regular = $billable->nextBillingDate()->toDateString();
    $patcher = app(MollieSubscriptionPatcher::class);

    // Basic → Plus: the coupon is locked to the 10.00 it was applied against, so 20.00
    // is owed every period from the next regular date.
    $patcher->updateForIntent($billable, deferralLifecycleIntent($billable, 'plus', 'monthly', 1));
    $billable->forceFill(['subscription_plan_code' => 'plus'])->save();
    $billable->refresh();

    expect(DeferralLifecycleMollie::amount(DeferralLifecycleMollie::last()))->toBe('24.00')
        ->and(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBe($regular)
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(ChargeDeferral::META_KEY);

    // Plus → Basic: fully covered again, so the charge goes back behind the coupon.
    $patcher->updateForIntent($billable, deferralLifecycleIntent($billable, 'basic', 'monthly', 1));
    $billable->forceFill(['subscription_plan_code' => 'basic'])->save();
    $billable->refresh();

    expect(DeferralLifecycleMollie::amount(DeferralLifecycleMollie::last()))->toBe('12.00')
        ->and(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBe($deferral->toDateString())
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($deferral->toDateString());
});

it('ends the deferral on an interval change the coupon no longer covers', function (): void {
    // A 100 % coupon covers whatever the plan costs at the new interval only up to the
    // amount it was applied against: yearly Basic is 100.00, the coupon is locked to
    // 10.00, so the yearly charge is owed — from one year on, the reset cadence.
    [$billable, ] = deferralLifecycleCovered();

    app(MollieSubscriptionPatcher::class)->updateForIntent(
        $billable,
        deferralLifecycleIntent($billable, 'basic', 'yearly', 1),
    );

    $billable->forceFill(['subscription_interval' => SubscriptionInterval::Yearly])->save();
    $billable->refresh();
    $patch = DeferralLifecycleMollie::last();

    expect(DeferralLifecycleMollie::amount($patch))->toBe('108.00')
        ->and(DeferralLifecycleMollie::startDate($patch))->toBe(BillingTime::nowUtc()->addYearNoOverflow()->toDateString())
        ->and(ChargeDeferral::until($billable))->toBeNull();
});

it('does not move a regular charge date for a discount that never deferred anything', function (): void {
    // 50 %: Mollie holds the reduced amount on the regular cycle. Neither the stored
    // answer nor the legacy reading may call that "deferred" — a start date sent with
    // the next amount change would move a charge that was exactly where it belonged.
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'HALF',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'max_redemptions_per_billable' => 3,
    ]);
    [$billable, ] = deferralLifecycleCovered();
    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta[ChargeDeferral::META_KEY], $meta['active_recurring_coupon']);
    $billable->forceFill(['subscription_meta' => $meta])->save();
    $coupons->applyRecurringMarker($coupon, $billable->fresh(), 1000, 'monthly');
    $billable->refresh();

    expect(ChargeDeferral::until($billable))->toBeNull();

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: $coupons->computeMarkerDiscount($billable, 1500),
    );

    // 15.00 − 5.00 = 10.00 + 20 %; no start date.
    expect(DeferralLifecycleMollie::amount(DeferralLifecycleMollie::last()))->toBe('12.00')
        ->and(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBeNull();
});

it('does not call a fixed discount that covers only part of the charge a deferral', function (): void {
    // Fixed 10.00 applied against Basic + one seat (15.00): 5.00 was always owed, so the
    // charge was never deferred. The legacy reading compares the discount with the
    // marker's own base, and must not answer "deferred" here.
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'TENOFF',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'max_redemptions_per_billable' => 3,
    ]);
    [$billable, ] = deferralLifecycleCovered(stored: false);
    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta['active_recurring_coupon']);
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();
    $coupons->applyRecurringMarker($coupon, $billable->fresh(), 1500, 'monthly');
    $billable->refresh();

    expect(ChargeDeferral::until($billable))->toBeNull();

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 2,
        couponDiscountNet: $coupons->computeMarkerDiscount($billable, 2000),
    );

    expect(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBeNull();
});

// ── Cancel, resubscribe, a fresh subscription ───────────────────────────────

it('defers again on a resubscribe while the coupon still covers everything', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);

    app(CancelSubscription::class)->handle($billable, false, 'customer');
    app(ResubscribeSubscription::class)->handle($billable->fresh());

    $billable->refresh();
    $created = DeferralLifecycleMollie::last(CreateSubscriptionRequest::class);

    expect(DeferralLifecycleMollie::amount($created))->toBe('12.00')
        ->and(DeferralLifecycleMollie::startDate($created))->toBe($deferral->toDateString())
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($deferral->toDateString());
});

it('drops a stale deferral when a resubscribe is no longer fully covered', function (): void {
    // Cancelled while deferred; the coupon is switched off during the grace period.
    [$billable, $coupon] = deferralLifecycleCovered();

    app(CancelSubscription::class)->handle($billable, false, 'customer');
    $endsAt = $billable->fresh()->getBillingSubscriptionEndsAt();
    app(CouponService::class)->deactivate($coupon);

    app(ResubscribeSubscription::class)->handle($billable->fresh());

    $billable->refresh();
    $created = DeferralLifecycleMollie::last(CreateSubscriptionRequest::class);

    // Full price from the end of the period the grace ran to — and the row says so.
    expect(DeferralLifecycleMollie::amount($created))->toBe('12.00')
        ->and(DeferralLifecycleMollie::startDate($created))->toBe($endsAt->toDateString())
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(ChargeDeferral::META_KEY)
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and(ChargeDeferral::nextChargeDate($billable)->toDateString())->toBe($endsAt->toDateString());
});

it('never lets a new subscription inherit the previous one\'s deferral', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $meta = $billable->getBillingSubscriptionMeta();
    unset($meta['active_recurring_coupon']);
    $billable->forceFill(['subscription_meta' => $meta])->save();

    app(CreateSubscription::class)->handle($billable->fresh(), [
        'plan_code' => 'plus',
        'interval' => 'monthly',
    ]);

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey(ChargeDeferral::META_KEY)
        ->and(ChargeDeferral::nextChargeDate($billable)->toDateString())->toBe($billable->nextBillingDate()->toDateString());
});

it('answers nothing for a Local subscription, whatever is left in its meta', function (): void {
    // After a downgrade to the free plan.
    [$billable, ] = deferralLifecycleCovered();
    $billable->forceFill(['subscription_source' => SubscriptionSource::Local])->save();

    expect(ChargeDeferral::until($billable->fresh()))->toBeNull();
});

it('treats a deferral that has passed as history', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);

    Carbon::setTestNow($deferral->copy()->addMinute());

    try {
        expect(ChargeDeferral::until($billable->fresh()))->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

// ── The coupon is switched off while the charge is deferred ─────────────────

it('ends the deferral when the coupon behind it is deactivated', function (bool $stored): void {
    // "Deactivate the coupon — the marker is cleared on the next renewal" is the
    // documented way to end a recurring discount. A 100 % coupon has no renewal for the
    // nightly pass to hang that on: Mollie kept the start date months away, and the
    // customer stayed free until the coupon's own end no matter what the operator did.
    [$billable, $coupon] = deferralLifecycleCovered(stored: $stored);
    $regular = $billable->nextBillingDate()->toDateString();

    app(CouponService::class)->deactivate($coupon);
    app()->call([new PrepareUsageOverageJob, 'handle']);

    $billable->refresh();
    $patch = DeferralLifecycleMollie::last();

    // The period that is running stays free; the next one is charged, in full.
    expect($patch)->not->toBeNull()
        ->and(DeferralLifecycleMollie::amount($patch))->toBe('12.00')
        ->and(DeferralLifecycleMollie::startDate($patch))->toBe($regular)
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(ChargeDeferral::META_KEY)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    // And only once.
    DeferralLifecycleMollie::$sent = [];
    app()->call([new PrepareUsageOverageJob, 'handle']);

    expect(DeferralLifecycleMollie::last())->toBeNull();
})->with(['deferral stored' => true, 'deferred before the date was stored' => false]);

it('leaves a deferral alone while its coupon is active', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);

    app()->call([new PrepareUsageOverageJob, 'handle']);

    expect(DeferralLifecycleMollie::$sent)->toBe([])
        ->and(ChargeDeferral::until($billable->fresh())?->toIso8601String())->toBe($deferral->toIso8601String());
});

it('leaves the extension days of a deferral alone once the coupon itself has run out', function (): void {
    // Coupon over, 20 days of a period extension still ahead: those days were given.
    [$billable, $coupon] = deferralLifecycleCovered();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['active_recurring_coupon']['valid_until'] = BillingTime::nowUtc()->subDay()->toIso8601String();
    $meta[ChargeDeferral::META_KEY] = BillingTime::nowUtc()->addDays(20)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();
    app(CouponService::class)->deactivate($coupon);

    app()->call([new PrepareUsageOverageJob, 'handle']);

    expect(collect(DeferralLifecycleMollie::$sent)->filter(fn ($r) => $r instanceof UpdateSubscriptionRequest)->all())->toBe([])
        ->and(ChargeDeferral::until($billable->fresh())?->toDateString())->toBe(BillingTime::nowUtc()->addDays(20)->toDateString());
});

it('retries ending the deferral when Mollie is unreachable', function (): void {
    [$billable, $coupon] = deferralLifecycleCovered();
    $regular = $billable->nextBillingDate()->toDateString();

    app(CouponService::class)->deactivate($coupon);
    DeferralLifecycleMollie::$failNext = 1;
    app()->call([new PrepareUsageOverageJob, 'handle']);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;

    // Nothing reached Mollie, so the row still says "deferred" — which is the truth.
    expect($pending['reason'] ?? null)->toBe('recurring_coupon_expiry_reset')
        ->and(ChargeDeferral::until($billable))->not->toBeNull();

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle(app(MollieSubscriptionPatcher::class), app(CouponService::class));

    $billable->refresh();

    expect(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBe($regular)
        ->and(DeferralLifecycleMollie::amount(DeferralLifecycleMollie::last()))->toBe('12.00')
        ->and(ChargeDeferral::until($billable))->toBeNull()
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
});

// ── A PATCH that fails ──────────────────────────────────────────────────────

it('keeps the stored deferral when the PATCH that would end it fails', function (): void {
    // The stored date is what Mollie holds. A seat is added, the PATCH throws: Mollie
    // is still deferred, and the row must not claim otherwise.
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);

    DeferralLifecycleMollie::$failNext = 1;

    expect(fn () => app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable,
        planCode: 'basic',
        interval: 'monthly',
        addons: [],
        extraSeats: 1,
        couponDiscountNet: 1000,
    ))->toThrow(RuntimeException::class);

    expect(ChargeDeferral::until($billable->fresh())?->toIso8601String())->toBe($deferral->toIso8601String());
});

it('re-sends the appended date on retry without stretching the billing period', function (): void {
    [$billable, ] = deferralLifecycleCovered();
    $deferral = ChargeDeferral::until($billable);
    $periodEnd = $billable->nextBillingDate()->toIso8601String();
    $target = $deferral->copy()->addDays(14);

    $patcher = app(MollieSubscriptionPatcher::class);
    DeferralLifecycleMollie::$failNext = 1;
    $patcher->deferNextChargeDate($billable, $target, periodFollows: false);

    $billable->refresh();
    $pending = $billable->getBillingSubscriptionMeta()['pending_subscription_patch'] ?? null;
    expect($pending['reason'] ?? null)->toBe('next_charge_date');

    $payload = $pending + ['billable_type' => $billable::class, 'billable_id' => $billable->getKey()];
    (new RetrySubscriptionPatchJob($payload, (string) $pending['first_attempt_at']))
        ->handle($patcher, app(CouponService::class));

    $billable->refresh();

    expect(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBe($target->toDateString())
        ->and(ChargeDeferral::until($billable)->toIso8601String())->toBe($target->toIso8601String())
        // The period keeps its own cycle: no override was written for a charge-only move.
        ->and($billable->nextBillingDate()->toIso8601String())->toBe($periodEnd)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('next_charge_date_override');
});

// ── Returning to the regular date ───────────────────────────────────────────

it('does not take a period extension back when a later change re-sends the charge date', function (): void {
    // Seat added under the 100 % coupon: the charge is back on the regular date P.
    // Then 14 days are given (charge on P + 14). Then a second seat. The marker still
    // "covers its own base", which is how a subscription deferred by an older release
    // is recognised — and that reading sent the bare period boundary P again, pulling
    // the charge 14 days forward while the row went on saying P + 14.
    [$billable, ] = deferralLifecycleCovered();
    $patcher = app(MollieSubscriptionPatcher::class);
    $coupons = app(CouponService::class);
    $regular = Carbon::instance($billable->nextBillingDate());

    $patcher->updateRecurringAmount(
        billable: $billable, planCode: 'basic', interval: 'monthly', addons: [], extraSeats: 1,
        couponDiscountNet: $coupons->computeMarkerDiscount($billable, 1500),
    );
    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    $extension = $coupons->create(['code' => 'PLUS14', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 14]);
    $coupons->validate('PLUS14', $billable->fresh(), []);
    $coupons->redeem($extension, $billable->fresh(), []);

    $billable->refresh();
    $extended = $regular->copy()->addDays(14);
    expect($billable->nextBillingDate()->toDateString())->toBe($extended->toDateString());

    DeferralLifecycleMollie::$sent = [];
    $patcher->updateRecurringAmount(
        billable: $billable, planCode: 'basic', interval: 'monthly', addons: [], extraSeats: 2,
        couponDiscountNet: $coupons->computeMarkerDiscount($billable, 2000),
    );

    $sent = DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last());

    // Either no date at all, or the one the customer was promised — never an earlier one.
    expect($sent === null || $sent === $extended->toDateString())->toBeTrue(
        "the charge was moved to {$sent}, the extension had put it on {$extended->toDateString()}"
    );
});

it('returns a trial\'s charge to the trial end, not to a month after signup', function (): void {
    // Trial with a fully covering coupon; a seat is added during the trial. The coupon
    // no longer covers everything, so the charge leaves the deferred date — for the
    // trial end, which is when a trial is charged.
    [$billable, ] = deferralLifecycleCovered(daysIntoPeriod: 4);
    $trialEnd = BillingTime::nowUtc()->addDays(10);
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => $trialEnd,
    ])->save();

    app(MollieSubscriptionPatcher::class)->updateRecurringAmount(
        billable: $billable->fresh(), planCode: 'basic', interval: 'monthly', addons: [], extraSeats: 1,
        couponDiscountNet: app(CouponService::class)->computeMarkerDiscount($billable->fresh(), 1500),
    );

    $meta = $billable->fresh()->getBillingSubscriptionMeta();
    $meta['seat_count'] = 2;
    $billable->forceFill(['subscription_meta' => $meta])->save();

    expect(DeferralLifecycleMollie::startDate(DeferralLifecycleMollie::last()))->toBe($trialEnd->toDateString())
        ->and(ChargeDeferral::until($billable->fresh()))->toBeNull();
});
