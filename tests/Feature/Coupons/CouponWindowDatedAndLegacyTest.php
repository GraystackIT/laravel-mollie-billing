<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\CreateSubscription;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\PlanWaiver;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * Two neighbours of the decision to count a coupon's window in billing periods
 * (17 November 2026, docs/decisions.md):
 *
 *  - A code that ends on a DATE stays bound to it. Under a fully covering one Mollie is
 *    first charged on the first regular charge the date no longer covers — a renewal is
 *    discounted while the code is valid at the start of its day — and never on the day
 *    after the end, which lies inside a period and moved the billing day for good.
 *  - Markers written before the change keep the date they were given. Nothing that is
 *    running is moved.
 *
 * Pro 29.00 net, DE 19 %: 34.51.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

function datedCouponCreate(string $validUntil, array $extra = []): void
{
    app(CouponService::class)->create([
        'code' => 'DATED', 'name' => 'Free until a date', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
        'valid_until' => Carbon::parse($validUntil, 'UTC'),
    ] + $extra);
}

/** @param list<string> $charged */
function datedCouponRenew(LongRunWorld $world, array &$charged): BillingInvoice
{
    $payment = longRunRenew($world);
    $charged[] = substr((string) $payment->createdAt, 0, 10);
    $invoice = longRunAssertRenewal($world, $payment, '34.51');

    expect(collect((array) $invoice->line_items)->where('kind', 'coupon')->count())->toBe(0);

    return $invoice;
}

it('first charges a code that ends on a date on the first regular charge it no longer covers', function (string $signup, string $validUntil, array $extra, array $expectedCharges): void {
    datedCouponCreate($validUntil, $extra);

    $world = longRunStartMandateOnly('monthly', $signup, 'DATED');
    $billable = $world->billable->fresh();

    expect($world->cadenceStart?->toDateString())->toBe($expectedCharges[0], 'Mollie\'s start date')
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe($expectedCharges[0])
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($expectedCharges[0]);

    $world->lastInvoice = null;
    $charged = [];

    foreach ($expectedCharges as $i => $date) {
        // The nights of the period before each charge, so the free periods turn over.
        if ($i === 0) {
            for ($night = Carbon::parse(substr($signup, 0, 10).' 02:00:00', 'UTC')->addDay(); $night->lessThan(Carbon::parse($date.' 06:00:00', 'UTC')); $night = $night->copy()->addDay()) {
                longRunNight($world, $night->toDateTimeString());
            }
        }

        datedCouponRenew($world, $charged);
    }

    expect($charged)->toBe($expectedCharges);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    // Cycle on the 15th. Ends on the 10th: the 15 February renewal is covered, 15 March is
    // the first that is not. The day after the end was 11 March.
    'ends between two renewals' => ['2027-01-15 10:00:00', '2027-03-10 00:00:00', [], ['2027-03-15', '2027-04-15', '2027-05-15']],
    // Ends at midnight on a renewal day: that renewal is no longer covered (was 16 March).
    'ends at the start of a renewal day' => ['2027-01-15 10:00:00', '2027-03-15 00:00:00', [], ['2027-03-15', '2027-04-15', '2027-05-15']],
    // Ends at noon on a renewal day: the code is still valid at the start of that day, so
    // the renewal is covered — the books would discount it — and 15 April is the first.
    'ends at noon on a renewal day' => ['2027-01-15 10:00:00', '2027-03-15 12:00:00', [], ['2027-04-15', '2027-05-15', '2027-06-15']],
    // Cycle on the 31st: 28 February is covered, 31 March is the first (was 2 March).
    'ends on 1 March on a cycle on the 31st' => ['2027-01-31 10:00:00', '2027-03-01 00:00:00', [], ['2027-03-31', '2027-04-30', '2027-05-31']],
    // Both limits: the date comes first and decides.
    'a count of six, the date first' => ['2027-01-15 10:00:00', '2027-02-20 00:00:00', ['discount_periods' => 6], ['2027-03-15', '2027-04-15', '2027-05-15']],
    // Both limits: the count comes first and decides.
    'a date in a year, the count of two first' => ['2027-01-31 10:00:00', '2028-01-31 00:00:00', ['discount_periods' => 2], ['2027-03-31', '2027-04-30', '2027-05-31']],
]);

it('first charges a dated code from the dashboard on a regular charge too', function (): void {
    // Paid from 15 December; on 5 January a code that covers everything until 20 February
    // is typed in. 15 January and 15 February are covered, 15 March is the first charge.
    datedCouponCreate('2027-02-20 00:00:00');
    $world = longRunStart('monthly', '2026-12-15 10:00:00', '34.51');

    Carbon::setTestNow(Carbon::parse('2027-01-05 12:00:00', 'UTC'));
    $outcome = app(RedeemCouponFromPortal::class)->handle($world->billable->fresh(), 'DATED');
    $billable = $world->billable->fresh();

    expect($outcome->redeemed)->toBeTrue($outcome->message)
        ->and($world->cadenceStart?->toDateString())->toBe('2027-03-15')
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe('2027-03-15')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe('2027-03-15');
});

/**
 * A subscription signed up on 31 December 2026 under a code for three free periods, as
 * the release before 17 November 2026 wrote it: the window 31 Dec + 3 × 30 + 1 days, the
 * charge deferred to the day after, 2 April — at Mollie and in the books.
 */
function legacyDeferredWorld(bool $storedDeferral = true): LongRunWorld
{
    app(CouponService::class)->create([
        'code' => 'FREE3', 'name' => 'Three periods free', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 3,
    ]);

    $world = longRunStartMandateOnly('monthly', '2026-12-31 10:00:00', 'FREE3');

    $meta = $world->billable->getBillingSubscriptionMeta();
    unset($meta['active_recurring_coupon']['charge_resumes_at']);
    $meta['active_recurring_coupon']['valid_until'] = '2027-04-01T10:00:00+00:00';

    if ($storedDeferral) {
        $meta[ChargeDeferral::META_KEY] = '2027-04-02T10:00:00+00:00';
    } else {
        unset($meta[ChargeDeferral::META_KEY]);
    }

    $world->billable->forceFill(['subscription_meta' => $meta])->save();
    $world->billable = $world->billable->fresh();
    $world->cadenceStart = \Carbon\CarbonImmutable::parse('2027-04-02 00:00:00', 'UTC');
    $world->cadenceCount = 0;

    return $world;
}

it('keeps the date a marker of the previous release was given, and charges on it', function (bool $storedDeferral): void {
    $world = legacyDeferredWorld($storedDeferral);
    $billable = $world->billable->fresh();

    // The books, the portal and Mollie still say 2 April …
    expect(ChargeDeferral::until($billable)?->toDateString())->toBe('2027-04-02')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe('2027-04-02');

    // … and a re-price under the same full coverage sends 2 April again, not 31 March.
    Carbon::setTestNow(Carbon::parse('2027-01-20 10:00:00', 'UTC'));
    app(PlanWaiver::class)->syncMollie($billable);

    expect(end($world->patches)['startDate'] ?? null)->toBe('2027-04-02')
        ->and($world->cadenceStart?->toDateString())->toBe('2027-04-02')
        ->and(ChargeDeferral::until($world->billable->fresh())?->toDateString())->toBe('2027-04-02');

    for ($night = Carbon::parse('2027-01-21 02:00:00', 'UTC'); $night->lessThan(Carbon::parse('2027-04-02 06:00:00', 'UTC')); $night = $night->copy()->addDay()) {
        longRunNight($world, $night->toDateTimeString());
    }

    $world->lastInvoice = null;
    $charged = [];

    foreach (range(1, 2) as $i) {
        datedCouponRenew($world, $charged);
    }

    // The customer's day stays what it became under the old rule.
    expect($charged)->toBe(['2027-04-02', '2027-05-02']);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(['with the stored deferral' => [true], 'deferred before the value was stored' => [false]]);

it('creates a subscription under a marker of the previous release on the day after its window, as before', function (): void {
    // A resubscribe re-creates the Mollie subscription from the marker it finds.
    $world = legacyDeferredWorld();
    $billable = $world->billable->fresh();
    // A resubscribe follows a cancelled subscription: Mollie keeps descriptions unique
    // among the active ones, so the one before has to be gone.
    $world->cancelled = true;

    Carbon::setTestNow(Carbon::parse('2027-01-20 10:00:00', 'UTC'));
    app(CreateSubscription::class)->handle($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'recurring_discount_net' => 2900,
        'mandate_id' => 'mdt_longrun',
    ]);

    expect($world->cadenceStart?->toDateString())->toBe('2027-04-02')
        ->and(ChargeDeferral::until($billable->fresh())?->toDateString())->toBe('2027-04-02');
});

/** A 14-day trial under a code that covers one period in full — the €0 mandate checkout. */
function deferredChargeDayTrial(string $at): LongRunWorld
{
    app(CouponService::class)->create([
        'code' => 'TRIALFREE', 'name' => 'One period free', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 1,
    ]);

    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create(['name' => 'Charge Day Co', 'email' => uniqid('charge-day-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    $world = LongRunWorld::$current = new LongRunWorld($billable, 'monthly');

    $world->payments['tr_cd_trial'] = LongRunPayment::make([
        'id' => 'tr_cd_trial', 'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => Carbon::now()->toIso8601String(), 'paidAt' => Carbon::now()->toIso8601String(),
        'subscriptionId' => null, 'customerId' => 'cst_longrun', 'mandateId' => 'mdt_longrun', 'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_coupon_code' => 'TRIALFREE',
            'pending_subscription_trial_days' => 14,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_cd_trial'])->assertStatus(200);
    $world->billable = $billable->fresh();

    return $world;
}

it('names today on the day of a deferred first charge after a trial, before the charge has arrived', function (bool $nightsRan): void {
    // Trial 1 → 15 January, then one period free: Mollie collects on 15 February.
    $world = deferredChargeDayTrial('2027-01-01 10:00:00');

    expect($world->cadenceStart?->toDateString())->toBe('2027-02-15');

    if ($nightsRan) {
        foreach (['2027-01-16 02:00:00', '2027-02-14 02:00:00', '2027-02-15 02:00:00'] as $night) {
            longRunNight($world, $night);
            \GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob::dispatchSync();
        }
    }

    // Noon on the 15th: past the hour the deferral was stored with, Mollie's charge not
    // yet delivered. The charge is today — not the trial end a month ago.
    Carbon::setTestNow(Carbon::parse('2027-02-15 12:00:00', 'UTC'));

    expect(app(NextChargeSummary::class)->for($world->billable->fresh())['date']?->toDateString())->toBe('2027-02-15');
})->with(['before any nightly pass' => [false], 'after the nightly passes' => [true]]);

it('names today on the day of a deferred charge that is off the period boundary, before the charge has arrived', function (): void {
    // A marker of the previous release: periods on the 31st, the charge deferred to 2 April.
    $world = legacyDeferredWorld();

    for ($night = Carbon::parse('2027-01-01 02:00:00', 'UTC'); $night->lessThan(Carbon::parse('2027-04-02 06:00:00', 'UTC')); $night = $night->copy()->addDay()) {
        longRunNight($world, $night->toDateTimeString());
    }

    Carbon::setTestNow(Carbon::parse('2027-04-02 12:00:00', 'UTC'));

    // Not 30 April, the end of the period that began on 31 March.
    expect(app(NextChargeSummary::class)->for($world->billable->fresh())['date']?->toDateString())->toBe('2027-04-02');
});

it('counts free plan periods on the billing day of a cycle on the 31st', function (string $cadence): void {
    // Paid from 31 January; on 10 February an operator gives two periods with the plan free.
    // The next charge is 28 February — the 31st, shortened. Free: 28 February and
    // 31 March; Mollie collects again on 30 April, and the cycle stays on the 31st.
    $world = longRunStart('monthly', '2027-01-31 10:00:00', '34.51');
    $world->cadence = $cadence;

    Carbon::setTestNow(Carbon::parse('2027-02-10 12:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\AdminExtensionService::class)->freePlanPeriods($world->billable->fresh(), 2);
    $billable = $world->billable->fresh();
    $marker = app(PlanWaiver::class)->marker($billable);

    expect(Carbon::parse((string) $marker['charge_resumes_at'])->toDateString())->toBe('2027-04-30')
        ->and($world->cadenceStart?->toDateString())->toBe('2027-04-30')
        ->and(ChargeDeferral::until($billable)?->toDateString())->toBe('2027-04-30')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe('2027-04-30');

    for ($night = Carbon::parse('2027-02-11 02:00:00', 'UTC'); $night->lessThan(Carbon::parse('2027-04-30 06:00:00', 'UTC')); $night = $night->copy()->addDay()) {
        longRunNight($world, $night->toDateTimeString());
    }

    expect($world->billable->fresh()->getBillingPeriodStartsAt()->toDateString())->toBe('2027-03-31');

    $world->lastInvoice = null;
    $charged = [];

    foreach (range(1, 3) as $i) {
        datedCouponRenew($world, $charged);
    }

    expect($charged)->toBe(['2027-04-30', '2027-05-31', '2027-06-30']);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(['drift' => ['drift'], 'clamp' => ['clamp']]);
