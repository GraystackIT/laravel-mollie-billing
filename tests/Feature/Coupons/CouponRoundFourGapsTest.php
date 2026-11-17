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
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * What the previous round reported as "not covered by a test", one test each.
 *
 * Pro 50.00 (2 seats included) + SSO 15.00, 20 % VAT.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => ['sso'],
        'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.addons.sso', [
        'name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]],
    ]);

    RoundFourMollie::$sent = [];
    RoundFourMollie::$onSend = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        RoundFourMollie::$sent[] = $request;

        if (RoundFourMollie::$onSend !== null) {
            (RoundFourMollie::$onSend)();
        }

        return (object) ['id' => 'sub_r4'];
    });
});

final class RoundFourMollie
{
    /** @var list<object> */
    public static array $sent = [];

    /** Runs while Mollie is being called — what another request does in the meantime. */
    public static ?Closure $onSend = null;

    public static function last(string $class): ?object
    {
        $matching = array_values(array_filter(self::$sent, fn ($r) => $r instanceof $class));

        return $matching === [] ? null : end($matching);
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
function roundFourPaying(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'R4', 'email' => uniqid('r4-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(10),
        'active_addon_codes' => [],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_r4', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_r4',
        'mollie_mandate_id' => 'mdt_r4',
    ], $overrides))->save();

    return $billable->fresh();
}

// ── The discount_periods migration, backwards ───────────────────────────────

it('rolls the discount_periods migration back to the meaning the column had before', function (): void {
    $coupons = app(CouponService::class);
    $recurring = $coupons->create([
        'code' => 'DOWN6', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 6,
    ]);
    $dated = $coupons->create([
        'code' => 'DOWNDATE', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
        'discount_periods' => null, 'valid_until' => '2030-01-01 00:00:00', 'max_redemptions_per_billable' => 2,
    ]);
    $credits = $coupons->create([
        'code' => 'DOWNCREDITS', 'type' => CouponType::Credits, 'credits_payload' => ['tokens' => 1], 'max_redemptions_per_billable' => 4,
    ]);

    $migration = require __DIR__.'/../../../database/migrations/2026_11_16_000001_add_discount_periods_to_coupons_table.php';
    $migration->down();

    try {
        expect(Schema::hasColumn('coupons', 'discount_periods'))->toBeFalse()
            // The period count is back where the previous release reads it …
            ->and((int) DB::table('coupons')->where('id', $recurring->id)->value('max_redemptions_per_billable'))->toBe(6)
            // … a coupon without one keeps its per-customer limit, as does every other type.
            ->and((int) DB::table('coupons')->where('id', $dated->id)->value('max_redemptions_per_billable'))->toBe(2)
            ->and((int) DB::table('coupons')->where('id', $credits->id)->value('max_redemptions_per_billable'))->toBe(4);

        // down() twice is harmless, and up() restores the split.
        $migration->down();
    } finally {
        $migration->up();
    }

    expect((array) DB::table('coupons')->where('id', $recurring->id)->first(['discount_periods', 'max_redemptions_per_billable']))
        ->toBe(['discount_periods' => 6, 'max_redemptions_per_billable' => 1]);
});

// ── Renewal rows written before they were marked ────────────────────────────

it('counts unmarked renewal rows of an older release as redemptions, so a second redemption is refused', function (): void {
    // Pinned as it is: nothing tells such a row from a grant, and guessing "these are
    // renewals" would let a customer redeem a code more often than it allows.
    $billable = roundFourPaying();
    $coupons = app(CouponService::class);
    $coupon = $coupons->create([
        'code' => 'OLDROWS', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10,
        'discount_periods' => 2, 'max_redemptions_per_billable' => 2,
    ]);

    // One grant and one renewal, as the previous release wrote them: no snapshot.
    foreach ([40, 10] as $daysAgo) {
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'applied_at' => BillingTime::nowUtc()->subDays($daysAgo),
            'discount_amount_net' => 500,
        ]);
    }

    expect(fn () => $coupons->validate('OLDROWS', $billable, ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]))
        ->toThrow(InvalidCouponException::class, 'per_billable_limit_reached');

    // Marked the way this release writes them, the same history leaves the second slot.
    CouponRedemption::query()->where('coupon_id', $coupon->id)->latest('applied_at')->first()
        ->forceFill(['grant_applied_snapshot' => ['mode' => 'recurring_renewal']])->save();

    expect($coupons->validate('OLDROWS', $billable, ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000])->id)
        ->toBe($coupon->id);
});

// ── A 100 % code from the portal, to the day ────────────────────────────────

it('defers a fully covering portal code to the charge N periods after the first, on the billing day', function (int $periods, string $expected): void {
    // The period began on 31 January; it is 10 February. The first charge the code
    // covers is February's last day, and the billing day is the 31st: N periods on from
    // there are calendar months on the 31st, shortened where a month has no 31st. Until
    // 17 November 2026 this was `first charge + N × 30 + 2` days — 1 April for one period,
    // and the subscription was charged on the 1st from then on.
    Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'UTC'));

    try {
        $billable = roundFourPaying(['subscription_period_starts_at' => Carbon::parse('2027-01-31 10:00:00', 'UTC')]);
        $firstCharge = Carbon::instance($billable->nextBillingDate());
        app(CouponService::class)->create([
            'code' => 'FULL', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => $periods,
        ]);

        $outcome = app(RedeemCouponFromPortal::class)->handle($billable, 'FULL');

        $billable->refresh();
        $patch = RoundFourMollie::last(UpdateSubscriptionRequest::class);
        $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'];

        expect($firstCharge->toDateString())->toBe('2027-02-28')
            ->and($outcome->redeemed)->toBeTrue()
            ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expected)
            ->and((string) RoundFourMollie::property($patch, 'startDate'))->toBe($expected)
            ->and(RoundFourMollie::property($patch, 'amount')->value)->toBe('60.00')
            // The window ends the day before; the marker names the same charge.
            ->and(Carbon::parse($marker['valid_until'])->toDateString())->toBe(Carbon::parse($expected)->subDay()->toDateString())
            ->and(Carbon::parse($marker['charge_resumes_at'])->toDateString())->toBe($expected)
            ->and($outcome->message)->toContain(BillingTime::display(Carbon::parse($expected, 'UTC'), $billable)->translatedFormat('d. M Y'));
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'one period' => [1, '2027-03-31'],
    'two periods' => [2, '2027-04-30'],
    'three periods' => [3, '2027-05-31'],
    'six periods' => [6, '2027-08-31'],
    // Through the leap February: the 29th.
    'twelve periods' => [12, '2028-02-29'],
]);

// ── The same admin action twice in a row ────────────────────────────────────

it('gives free time once when the same admin action arrives twice within seconds', function (string $method, int $amount): void {
    // A double click sends the action twice. Each mints its own code, so nothing made
    // the second one a no-op: fourteen days became twenty-eight.
    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    $service = app(AdminExtensionService::class);
    $nextCharge = Carbon::instance($billable->nextBillingDate());

    $service->{$method}($billable, $amount);
    $after = [$billable->fresh()->nextBillingDate()->toIso8601String(), $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null];

    expect(fn () => $service->{$method}($billable->fresh(), $amount))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect([$billable->fresh()->nextBillingDate()->toIso8601String(), $billable->fresh()->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null])->toBe($after)
        ->and(Coupon::query()->count())->toBe(1)
        ->and(CouponRedemption::query()->count())->toBe(1);

    // A deliberate second time, a little later, is a second gift.
    Carbon::setTestNow(BillingTime::nowUtc()->addSeconds(AdminExtensionService::REPEAT_GUARD_SECONDS + 1));

    try {
        $service->{$method}($billable->fresh(), $amount);
    } finally {
        Carbon::setTestNow();
    }

    expect(CouponRedemption::query()->count())->toBe(2)
        ->and($nextCharge)->not->toBeNull();
})->with([
    'extend by days' => ['extendByDays', 14],
    'free plan periods' => ['freePlanPeriods', 2],
]);

it('does not hold the guard after an action that was refused', function (): void {
    // Refused (no subscription), corrected, tried again at once: must not be told to wait.
    /** @var TestBillable $nobody */
    $nobody = TestBillable::create(['name' => 'Nobody', 'email' => 'nobody-r4@x.test']);
    $service = app(AdminExtensionService::class);

    expect(fn () => $service->extendByDays($nobody, 14))->toThrow(InvalidCouponException::class);
    expect(fn () => $service->extendByDays($nobody, 14))->toThrow(InvalidCouponException::class);
});

it('extends a gift once when "extend the gift" arrives twice within seconds', function (): void {
    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    $coupon = app(CouponService::class)->accessGrantCoupon('GIFT-R4', 'pro', 'monthly', [], 60);
    app(PlanWaiver::class)->grant($billable, $coupon, 'pro', 'monthly', [], BillingTime::nowUtc()->addDays(60), null, PlanWaiver::ON_EXPIRY_END);
    $service = app(AdminExtensionService::class);

    $newEnd = $service->extendGrant($billable->fresh(), 30);

    expect(fn () => $service->extendGrant($billable->fresh(), 30))->toThrow(InvalidSubscriptionStateException::class);

    expect(Carbon::parse((string) app(PlanWaiver::class)->marker($billable->fresh())['valid_until'])->toIso8601String())
        ->toBe($newEnd->toIso8601String());
});

it('refuses the identical admin action from a second process whatever the cache store is', function (string $store, string $method, int $amount): void {
    // The guard used to be a cache lock. On the `array` store that lock lives in one
    // process's memory, on `null` every lock is granted — and an operator's two clicks
    // are two requests, usually served by two PHP processes. The second gave again.
    config()->set('cache.stores.null', ['driver' => 'null']);
    config()->set('cache.default', $store);

    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    app(AdminExtensionService::class)->{$method}($billable, $amount);

    // A second process: a cache manager of its own (an empty `array` store, or the
    // `null` store again) and a service resolved afresh. Only the database is shared.
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    \Illuminate\Support\Facades\Cache::clearResolvedInstances();
    app()->forgetInstance(AdminExtensionService::class);

    expect(fn () => app(AdminExtensionService::class)->{$method}($billable->fresh(), $amount))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect(Coupon::query()->count())->toBe(1)
        ->and(CouponRedemption::query()->count())->toBe(1);

    // And it runs out like before: a deliberate second time a little later is given.
    Carbon::setTestNow(BillingTime::nowUtc()->addSeconds(AdminExtensionService::REPEAT_GUARD_SECONDS + 1));

    try {
        app(AdminExtensionService::class)->{$method}($billable->fresh(), $amount);
    } finally {
        Carbon::setTestNow();
    }

    expect(CouponRedemption::query()->count())->toBe(2);
})->with([
    'array store' => ['array'],
    'null store' => ['null'],
])->with([
    'extend by days' => ['extendByDays', 14],
    'free plan periods' => ['freePlanPeriods', 2],
]);

it('keeps an identical action out while the first one is still running, from another process', function (): void {
    // Two tabs, not a double click: the second request arrives while the first is still
    // talking to Mollie. The claim is on the row from the start of the action.
    config()->set('cache.default', 'array');
    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    $refused = null;

    RoundFourMollie::$onSend = function () use ($billable, &$refused): void {
        RoundFourMollie::$onSend = null;
        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        \Illuminate\Support\Facades\Cache::clearResolvedInstances();

        try {
            app(AdminExtensionService::class)->extendByDays($billable->fresh(), 14);
            $refused = false;
        } catch (InvalidSubscriptionStateException) {
            $refused = true;
        }
    };

    app(AdminExtensionService::class)->extendByDays($billable, 14);

    expect($refused)->toBeTrue()
        ->and(CouponRedemption::query()->count())->toBe(1)
        // Settled: the entry now holds for the short window only, and a refused or failed
        // action leaves nothing behind.
        ->and($billable->fresh()->getBillingSubscriptionMeta()[AdminExtensionService::GUARD_META_KEY]['days:14']['state'] ?? null)->toBe('done');
});

it('lets an action whose process died run again once the running claim has expired', function (): void {
    // A claim marked "running" is what a process leaves behind when it dies mid-action.
    // It keeps the action out for RUNNING_GUARD_SECONDS, not for the short window of a
    // finished one — and not for longer.
    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    $meta = $billable->getBillingSubscriptionMeta();
    $meta[AdminExtensionService::GUARD_META_KEY] = ['days:14' => [
        'token' => 'dead-process', 'state' => 'running', 'at' => BillingTime::nowUtc()->toIso8601String(),
    ]];
    $billable->forceFill(['subscription_meta' => $meta])->save();
    $service = app(AdminExtensionService::class);
    $start = BillingTime::nowUtc();

    try {
        // Past the short window of a finished action: still held.
        Carbon::setTestNow($start->copy()->addSeconds(AdminExtensionService::REPEAT_GUARD_SECONDS + 1));
        expect(fn () => $service->extendByDays($billable->fresh(), 14))->toThrow(InvalidSubscriptionStateException::class);

        // A different amount is a different action, claim or not.
        $service->extendByDays($billable->fresh(), 7);

        // One second before the claim runs out: held; one second after: runs.
        Carbon::setTestNow($start->copy()->addSeconds(AdminExtensionService::RUNNING_GUARD_SECONDS - 1));
        expect(fn () => $service->extendByDays($billable->fresh(), 14))->toThrow(InvalidSubscriptionStateException::class);

        Carbon::setTestNow($start->copy()->addSeconds(AdminExtensionService::RUNNING_GUARD_SECONDS + 1));
        $service->extendByDays($billable->fresh(), 14);
    } finally {
        Carbon::setTestNow();
    }

    $guards = $billable->fresh()->getBillingSubscriptionMeta()[AdminExtensionService::GUARD_META_KEY] ?? [];

    expect(CouponRedemption::query()->count())->toBe(2)
        // The dead claim is gone, replaced by this action's own; the 7-day one has run out.
        ->and(array_keys($guards))->toBe(['days:14'])
        ->and($guards['days:14']['state'])->toBe('done')
        ->and($guards['days:14']['token'])->not->toBe('dead-process');
});

// ── Free plan periods on a cancelled subscription ───────────────────────────

it('keeps free plan periods given to a cancelled subscription for the resubscribe', function (): void {
    // Pinned: nothing is PATCHed (Mollie has cancelled the subscription), the waiver is
    // written, and a resubscribe during the grace period is priced from it.
    $billable = roundFourPaying(['active_addon_codes' => ['sso']]);
    app(CancelSubscription::class)->handle($billable, false, 'customer');
    $graceEnd = Carbon::instance($billable->fresh()->getBillingSubscriptionEndsAt());
    RoundFourMollie::$sent = [];

    $coupons = app(CouponService::class);
    $coupon = $coupons->create(['code' => 'FREE2', 'type' => CouponType::PlanWaiver, 'waiver_periods' => 2]);
    $coupons->validate('FREE2', $billable->fresh(), []);
    $coupons->redeem($coupon, $billable->fresh(), []);

    $marker = app(PlanWaiver::class)->marker($billable->fresh());

    expect($marker['max_applications'] ?? null)->toBe(2)
        ->and(RoundFourMollie::$sent)->toBe([])
        ->and($billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        // Counted from the charge the resubscribe will make: the end of the grace period.
        ->and(Carbon::parse((string) $marker['charge_resumes_at'])->toDateString())->toBe($graceEnd->copy()->addMonthsNoOverflow(2)->toDateString());

    app(ResubscribeSubscription::class)->handle($billable->fresh());

    $created = RoundFourMollie::last(CreateSubscriptionRequest::class);

    // SSO only: 15.00 + 20 %.
    expect(RoundFourMollie::property($created, 'amount')->value)->toBe('18.00')
        ->and((string) RoundFourMollie::property($created, 'startDate'))->toBe($graceEnd->toDateString());
});

// ── A discount that has run out, still sitting in the slot ──────────────────

it('accepts a new recurring coupon once the one in the slot has run out', function (string $how): void {
    // The marker is cleared by the renewal that follows its end. Until that renewal —
    // which is weeks away when extension days keep the charge deferred — the slot was
    // "taken" by a discount that no longer discounts anything.
    $billable = roundFourPaying();
    $coupons = app(CouponService::class);
    $old = $coupons->create([
        'code' => 'OLD10', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 2,
    ]);
    $coupons->applyRecurringMarker($old, $billable, 5000, 'monthly');

    $meta = $billable->fresh()->getBillingSubscriptionMeta();

    if ($how === 'date') {
        $meta['active_recurring_coupon']['valid_until'] = BillingTime::nowUtc()->subDay()->toIso8601String();
        $billable->forceFill(['subscription_meta' => $meta])->save();
    } elseif ($how === 'deactivated') {
        $coupons->deactivate($old);
    }

    $coupons->create([
        'code' => 'NEW20', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'discount_periods' => 3,
    ]);

    $outcome = app(RedeemCouponFromPortal::class)->handle($billable->fresh(), 'NEW20');

    $billable->refresh();

    expect($outcome->redeemed)->toBeTrue()
        ->and($billable->getBillingSubscriptionMeta()['active_recurring_coupon']['code'])->toBe('NEW20')
        ->and($coupons->computeMarkerDiscount($billable, 5000))->toBe(1000)
        // 50.00 − 20 % = 40.00 + 20 % VAT.
        ->and(RoundFourMollie::property(RoundFourMollie::last(UpdateSubscriptionRequest::class), 'amount')->value)->toBe('48.00');
})->with(['date' => 'date', 'deactivated' => 'deactivated']);

it('still refuses a second recurring coupon while the first one discounts', function (): void {
    $billable = roundFourPaying();
    $coupons = app(CouponService::class);
    $coupons->applyRecurringMarker($coupons->create([
        'code' => 'RUNS10', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 10, 'discount_periods' => 2,
    ]), $billable, 5000, 'monthly');
    $coupons->create([
        'code' => 'ALSO20', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 20, 'discount_periods' => 3,
    ]);

    expect(fn () => $coupons->validate('ALSO20', $billable->fresh(), ['planCode' => 'pro', 'interval' => 'monthly', 'orderAmountNet' => 5000]))
        ->toThrow(InvalidCouponException::class, 'recurring_conflict');
});
