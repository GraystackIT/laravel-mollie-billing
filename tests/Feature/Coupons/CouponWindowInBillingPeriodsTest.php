<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\RedeemCouponFromPortal;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * A recurring code limited to N periods counts the subscription's own billing periods —
 * calendar months or years on its billing day, shortened where a month does not have it —
 * not 30- or 365-day blocks (decided 17 November 2026, docs/decisions.md).
 *
 * Every way a code reaches a subscription is arranged so that the first period it
 * discounts starts on the same date S, on the billing day:
 *
 *  - checkout:       signed up on S, paying (50 %) or through the €0 mandate (100 %);
 *  - trial:          a 14-day trial that ends on S;
 *  - upgrade:        from the free plan on the plan page on S (€0 under 100 %, paid under 50 %);
 *  - dashboard:      typed into the dashboard of a subscription whose next charge is S;
 *  - plan change:    a subscription that renewed on S, upgraded to Business with the
 *                    code ten days later — the code discounts the change's own charge, so
 *                    the running period is its first;
 *  - seat change:    the same with two seats added instead (Pro with four seats);
 *  - addon change:   the same with the SSO addon enabled instead.
 *
 * A fully covering code is then first collected on the charge N periods after S, on the
 * billing day, and the cadence stays on that day; the portal, the stored deferral, the
 * marker and Mollie name that one date. A partial code discounts exactly N charges.
 *
 * Pro 29.00 net (290.00 yearly), Business 49.00 (490.00), DE 19 %, VAT per line:
 * Pro 34.51 / 345.10, at 50 % 17.25 / 172.55; Business 58.31 / 583.10, at 50 % 29.15 / 291.55.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);

    config()->set('mollie-billing-plans.plans.business', [
        'name' => 'Business', 'tier' => 3, 'included_seats' => 2, 'feature_keys' => [], 'allowed_addons' => ['sso', 'crm'],
        'intervals' => [
            'monthly' => ['base_price_net' => 4900, 'seat_price_net' => 500, 'included_usages' => ['Tokens' => 200]],
            'yearly' => ['base_price_net' => 49000, 'seat_price_net' => 5000, 'included_usages' => ['Tokens' => 2400]],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]],
            'yearly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 120]],
        ],
    ]);

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_longrun');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The billing cycles, written out: S and the seven charges after it.
 *
 * @return array<string, array{0: string, 1: list<string>}>  interval, cycle
 */
function couponPeriodCycles(): array
{
    return [
        'monthly on the 15th' => ['monthly', ['2027-01-15', '2027-02-15', '2027-03-15', '2027-04-15', '2027-05-15', '2027-06-15', '2027-07-15', '2027-08-15']],
        // Shortened in February, April and June, the 31st again in between.
        'monthly on the 31st' => ['monthly', ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31', '2027-08-31']],
        'yearly on the 15th' => ['yearly', ['2027-01-15', '2028-01-15', '2029-01-15', '2030-01-15', '2031-01-15', '2032-01-15', '2033-01-15', '2034-01-15']],
        'yearly on the 31st' => ['yearly', ['2027-01-31', '2028-01-31', '2029-01-31', '2030-01-31', '2031-01-31', '2032-01-31', '2033-01-31', '2034-01-31']],
    ];
}

/** @return array<string, array{0: string, 1: list<string>, 2: int}> interval, cycle, N */
function couponPeriodCases(): array
{
    $cases = [];

    foreach (couponPeriodCycles() as $name => [$interval, $cycle]) {
        foreach ([1, 2, 3] as $n) {
            $cases["{$name}, N = {$n}"] = [$interval, $cycle, $n];
        }
    }

    // Once: a yearly cycle from 29 February — the 28th in common years, the 29th in 2032.
    $cases['yearly from 29 February, N = 2'] = ['yearly', ['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29', '2033-02-28', '2034-02-28', '2035-02-28'], 2];

    return $cases;
}

/** Both answers Mollie can give for the charge after a short month (RenewalMonthEndCadenceTest). */
function couponPeriodCadences(): array
{
    return ['Mollie stays on the short day (drift)' => 'drift', 'Mollie returns to its start day (clamp)' => 'clamp'];
}

/**
 * Renew once and check that the invoice, the billable, the portal and Mollie agree on the
 * period — the same checks as RenewalMonthEndCadenceTest's.
 *
 * @param  list<string>  $charged
 */
function couponPeriodRenew(LongRunWorld $world, string $gross, array &$charged): BillingInvoice
{
    $payment = longRunRenew($world);

    expect($payment)->not->toBeNull('Mollie created no charge');

    $charged[] = substr((string) $payment->createdAt, 0, 10);
    $invoice = longRunAssertRenewal($world, $payment, $gross);
    $mollieNext = $world->nextChargeAt()?->toDateString();
    $billable = $world->billable->fresh();
    $what = 'renewal of '.end($charged);

    expect($invoice->period_start->toDateString())->toBe(end($charged), "{$what}: period start")
        ->and($billable->nextBillingDate()?->toDateString())->toBe($mollieNext, "{$what}: the package's period ends on another day than Mollie charges")
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($mollieNext, "{$what}: the portal shows another next charge than Mollie's")
        ->and($invoice->period_end->toDateString())->toBe($mollieNext, "{$what}: the invoiced period ends before or after Mollie's next charge");

    return $invoice;
}

/** @return array<string, string> */
function couponPeriodPaths(): array
{
    return ['checkout' => 'checkout', 'trial' => 'trial', 'upgrade from free' => 'upgrade', 'dashboard' => 'dashboard', 'plan change' => 'plan change', 'seat change' => 'seat change', 'addon change' => 'addon change'];
}

function couponPeriodGross(string $plan, string $interval, bool $half): string
{
    return [
        'pro' => ['monthly' => ['34.51', '17.25'], 'yearly' => ['345.10', '172.55']],
        'business' => ['monthly' => ['58.31', '29.15'], 'yearly' => ['583.10', '291.55']],
        // Pro with two extra seats: 29.00 + 2 × 5.00 (290.00 + 2 × 50.00).
        'pro4' => ['monthly' => ['46.41', '23.20'], 'yearly' => ['464.10', '232.05']],
        // Pro with SSO: 29.00 + 15.00 (290.00 + 150.00).
        'prosso' => ['monthly' => ['52.36', '26.18'], 'yearly' => ['523.60', '261.80']],
    ][$plan][$interval][$half ? 1 : 0];
}

function couponPeriodAt(string $date, string $time = '10:00:00'): string
{
    return "{$date} {$time}";
}

/** The €0 mandate checkout of a trial, as the checkout sends it. */
function couponPeriodStartTrial(string $interval, string $at, string $couponCode, int $trialDays): LongRunWorld
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Trial Co', 'email' => uniqid('cw-trial-').'@x.test', 'billing_country' => 'DE']);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();

    $world = LongRunWorld::$current = new LongRunWorld($billable, $interval);

    $world->payments['tr_cw_trial'] = LongRunPayment::make([
        'id' => 'tr_cw_trial',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'createdAt' => BillingTime::nowUtc()->toIso8601String(),
        'paidAt' => BillingTime::nowUtc()->toIso8601String(),
        'subscriptionId' => null,
        'customerId' => 'cst_longrun',
        'mandateId' => 'mdt_longrun',
        'countryCode' => 'DE',
        'metadata' => [
            'type' => 'mandate_only',
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => $interval,
            'pending_subscription_coupon_code' => $couponCode,
            'pending_subscription_trial_days' => $trialDays,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
        ],
    ]);

    test()->postJson(route('billing.webhook'), ['id' => 'tr_cw_trial'])->assertStatus(200);

    $world->billable = $billable->fresh();

    return $world;
}

/**
 * Set the case up on its path. Returns the world with the code applied, its first
 * discounted period starting on S.
 */
function couponPeriodWorld(string $path, string $interval, string $start, int $value, int $periods, string $cadence): LongRunWorld
{
    $s = CarbonImmutable::parse($start, 'UTC');
    $half = $value === 50;

    app(CouponService::class)->create([
        'code' => 'PERIODS', 'name' => 'Periods', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => $value,
        'discount_periods' => $periods,
    ]);

    switch ($path) {
        case 'checkout':
            $world = $half
                ? longRunStart($interval, couponPeriodAt($start), couponPeriodGross('pro', $interval, true), ['coupon_code' => 'PERIODS'])
                : longRunStartMandateOnly($interval, couponPeriodAt($start), 'PERIODS');
            break;

        case 'trial':
            $world = couponPeriodStartTrial($interval, $s->subDays(14)->format('Y-m-d').' 10:00:00', 'PERIODS', 14);
            expect($world->billable->getBillingTrialEndsAt()?->toDateString())->toBe($start);
            $world->lastInvoice = null;
            break;

        case 'upgrade':
            Carbon::setTestNow(Carbon::parse($s->subDays(10)->format('Y-m-d').' 10:00:00', 'UTC'));
            $billable = TestBillable::create(['name' => 'Upgrade Co', 'email' => uniqid('cw-up-').'@x.test', 'billing_country' => 'DE']);
            $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
            app(ActivateLocalSubscription::class)->handle($billable->fresh(), 'free', $interval);
            $world = LongRunWorld::$current = new LongRunWorld($billable->fresh(), $interval);

            Carbon::setTestNow(Carbon::parse(couponPeriodAt($start), 'UTC'));
            $gross = $half ? couponPeriodGross('pro', $interval, true) : '0.00';
            app(UpgradeLocalToMollie::class)->handle($world->billable->fresh(), [
                'plan_code' => 'pro', 'interval' => $interval, 'addon_codes' => [], 'extra_seats' => 0,
                'amount_gross' => \GraystackIT\MollieBilling\Support\BillingMoney::minorFromMollieAmount($gross, 'EUR'), 'coupon_code' => 'PERIODS',
            ]);
            $world->lastInvoice = longRunSettleSideCharge($world, couponPeriodAt($start, '10:05:00'));
            break;

        case 'dashboard':
            // Paid in full one period before S; the code is typed in ten days before S.
            $signup = $interval === 'yearly' ? $s->subYearNoOverflow() : $s->subMonthNoOverflow();
            $world = longRunStart($interval, couponPeriodAt($signup->toDateString()), couponPeriodGross('pro', $interval, false));
            Carbon::setTestNow(Carbon::parse($s->subDays(10)->format('Y-m-d').' 12:00:00', 'UTC'));
            $outcome = app(RedeemCouponFromPortal::class)->handle($world->billable->fresh(), 'PERIODS');
            expect($outcome->redeemed)->toBeTrue($outcome->message);
            $world->billable = $world->billable->fresh();
            break;

        case 'plan change':
        case 'seat change':
        case 'addon change':
            $world = longRunStart($interval, couponPeriodAt($start), couponPeriodGross('pro', $interval, false));
            $world->cadence = $cadence;
            Carbon::setTestNow(Carbon::parse($s->addDays(10)->format('Y-m-d').' 10:00:00', 'UTC'));
            $requestsBefore = count($world->requests);
            app(UpdateSubscription::class)->update(
                $world->billable->fresh(),
                match ($path) {
                    'plan change' => ['plan_code' => 'business'],
                    'seat change' => ['seats' => 4],
                    default => ['addons' => ['sso']],
                } + ['coupon_codes' => ['PERIODS']],
            );

            // The prorata is a payment of its own, unless the code leaves nothing to collect
            // (a yearly upgrade under 100 %: the charge less the code equals the refund) —
            // then the change is invoiced at once.
            $charged = collect(array_slice($world->requests, $requestsBefore))
                ->contains(fn (object $r): bool => $r instanceof \Mollie\Api\Http\Requests\CreatePaymentRequest);
            $world->lastInvoice = $charged
                ? longRunSettleSideCharge($world, $s->addDays(10)->format('Y-m-d').' 10:05:00')
                : BillingInvoice::query()->latest('id')->first();
            break;

        default:
            throw new InvalidArgumentException($path);
    }

    $world->cadence = $cadence;
    $world->billable = $world->billable->fresh();

    return $world;
}

/**
 * The nightly passes between now and the first charge: every night for a monthly cycle,
 * the nights around each boundary for a yearly one.
 *
 * @param  list<string>  $boundaries
 */
function couponPeriodNights(LongRunWorld $world, string $interval, array $boundaries, CarbonImmutable $until): void
{
    $nights = [];

    if ($interval === 'monthly') {
        for ($night = CarbonImmutable::parse(BillingTime::nowUtc()->toDateString().' 02:00:00', 'UTC')->addDay(); $night->lessThan($until); $night = $night->addDay()) {
            $nights[] = $night;
        }
    } else {
        foreach ($boundaries as $boundary) {
            foreach ([-1, 0, 1] as $offset) {
                $night = CarbonImmutable::parse($boundary.' 02:00:00', 'UTC')->addDays($offset);

                if ($night->greaterThan(BillingTime::nowUtc()) && $night->lessThan($until)) {
                    $nights[] = $night;
                }
            }
        }
    }

    foreach ($nights as $night) {
        longRunNight($world, $night->toDateTimeString());
        \GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob::dispatchSync();
        $world->billable = $world->billable->fresh();
    }
}

function couponPeriodCouponLines(BillingInvoice $invoice): int
{
    return collect((array) $invoice->line_items)->where('kind', 'coupon')->count();
}

it('first collects a fully covering code on the charge N periods on, on the billing day', function (string $path, string $cadence, string $interval, array $cycle, int $n): void {
    if ($path === 'dashboard' && $cycle[0] === '2028-02-29') {
        $this->markTestSkipped('A yearly subscription a year before 29 February 2028 renews on 28 February.');
    }

    $plan = match ($path) {
        'plan change' => 'business',
        'seat change' => 'pro4',
        'addon change' => 'prosso',
        default => 'pro',
    };
    $world = couponPeriodWorld($path, $interval, $cycle[0], 100, $n, $cadence);
    $billable = $world->billable->fresh();
    $firstCharge = $cycle[$n];
    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;

    // One date, wherever it is asked for: Mollie, the stored deferral, the portal and the
    // marker (whose window ends the day before).
    expect($world->cadenceStart?->toDateString())->toBe($firstCharge, 'Mollie\'s start date')
        ->and($world->heldValue)->toBe(couponPeriodGross($plan, $interval, false))
        // Stored as a deferral where it is not simply the next regular charge (N = 1 at a
        // checkout or an upgrade, where the code's one period is the one just opened).
        ->and(ChargeDeferral::nextChargeDate($billable)?->toDateString())->toBe($firstCharge, 'the next charge the package knows')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($firstCharge, 'the portal\'s next charge')
        ->and($marker)->not->toBeNull()
        ->and(Carbon::parse((string) $marker['valid_until'])->toDateString())->toBe(Carbon::parse($firstCharge)->subDay()->toDateString(), 'the end of the marker\'s window')
        ->and(Carbon::parse((string) ($marker['charge_resumes_at'] ?? ''))->toDateString())->toBe($firstCharge);

    // The free periods turn over on the billing day, and nobody is charged for them.
    $invoicesBefore = BillingInvoice::query()->count();
    couponPeriodNights($world, $interval, array_slice($cycle, 0, $n), CarbonImmutable::parse($firstCharge.' 06:00:00', 'UTC'));
    $billable = $world->billable->fresh();

    expect(BillingInvoice::query()->count())->toBe($invoicesBefore, 'a free period was invoiced')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($firstCharge, 'the portal before the first charge')
        ->and($world->cadenceStart?->toDateString())->toBe($firstCharge, 'Mollie\'s start date before the first charge');

    // The last free period is the Nth from S, and it ends with the first charge. A trial is
    // over at S: the subscription runs on, active, through the free periods — each one
    // turned over on the billing day and given its full quota.
    $quota = ['pro' => 100, 'pro4' => 100, 'prosso' => 100, 'business' => 200][$plan] * ($interval === 'yearly' ? 12 : 1);

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active, 'status in the free periods')
        ->and($billable->getBillingPeriodStartsAt()->toDateString())->toBe($cycle[$n - 1], 'start of the last free period')
        ->and($billable->nextBillingDate()?->toDateString())->toBe($firstCharge)
        ->and($billable->remainingBillingQuota('Tokens'))->toBe($quota, 'quota of the last free period');

    $world->lastInvoice = null;
    $charged = [];

    foreach (range(1, 3) as $i) {
        $invoice = couponPeriodRenew($world, couponPeriodGross($plan, $interval, false), $charged);

        expect(couponPeriodCouponLines($invoice))->toBe(0, 'a charge after the free periods carries a discount');
    }

    // On the billing day from the first charge on, under both answers Mollie can give.
    expect($charged)->toBe(array_slice($cycle, $n, 3));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(couponPeriodPaths())->with(couponPeriodCadences())->with(couponPeriodCases());

it('discounts exactly N charges with a partial code, on the billing day', function (string $path, string $cadence, string $interval, array $cycle, int $n): void {
    if ($path === 'dashboard' && $cycle[0] === '2028-02-29') {
        $this->markTestSkipped('A yearly subscription a year before 29 February 2028 renews on 28 February.');
    }

    $plan = match ($path) {
        'plan change' => 'business',
        'seat change' => 'pro4',
        'addon change' => 'prosso',
        default => 'pro',
    };
    $world = couponPeriodWorld($path, $interval, $cycle[0], 50, $n, $cadence);

    // Discounted already: the charge the code came with — the checkout's, the upgrade's,
    // the change's own prorata. A trial and the dashboard charge nothing when the code
    // arrives; their first discounted charge is the one on S.
    $before = in_array($path, ['checkout', 'upgrade', 'plan change', 'seat change', 'addon change'], true) ? 1 : 0;

    if ($before === 1) {
        expect(couponPeriodCouponLines($world->lastInvoice))->toBe(1, 'the charge the code came with is not discounted');
    }

    $charged = [];
    $lines = [];
    $expectedLines = [];

    // Every charge up to and including the first full-price one after the N discounted.
    foreach (range($before, $n) as $k) {
        $discounted = $k < $n;
        $invoice = couponPeriodRenew($world, couponPeriodGross($plan, $interval, $discounted), $charged);
        $lines[] = couponPeriodCouponLines($invoice);
        $expectedLines[] = $discounted ? 1 : 0;
    }

    // Exactly N discounted charges, the first full-price one is N periods after S, every
    // charge on the billing day.
    expect($charged)->toBe(array_slice($cycle, $before, $n + 1 - $before))
        ->and($lines)->toBe($expectedLines)
        ->and(array_sum($lines) + $before)->toBe($n);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(couponPeriodPaths())->with(couponPeriodCadences())->with(couponPeriodCases());
