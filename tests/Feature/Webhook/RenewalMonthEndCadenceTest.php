<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\UpdateSubscriptionRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';
require_once dirname(__DIR__).'/Lifecycle/Support.php';

/*
 * A Mollie subscription on the 29th, 30th or 31st keeps its day through the months that do
 * not have it: 31 Jan → 28 Feb → 31 Mar → 30 Apr → 31 May. The period an invoice states,
 * the billable's period, `nextBillingDate()`, the portal's next charge and Mollie's next
 * charge are one and the same day — under both answers Mollie can give for the charge
 * after a short month:
 *
 *  - "drift": every charge is the previous one plus a month — 31 Jan, 28 Feb, 28 Mar …
 *  - "clamp": every charge is start + n months — 31 Jan, 28 Feb, 31 Mar, 30 Apr …
 *
 * Which one Mollie does is measured by the sandbox canary (docs/sandbox-testing.md); the
 * package sends the next date itself wherever the two could differ.
 *
 * Pro 29.00 (2 seats included), extra seat 5.00, SSO 15.00. DE 19 %, VAT per line.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

/** Both answers Mollie can give after a short month. */
function monthEndCadences(): array
{
    return ['Mollie stays on the short day (drift)' => 'drift', 'Mollie returns to its start day (clamp)' => 'clamp'];
}

/**
 * The package and Mollie name the same next charge: the billable's period end, the
 * portal's next-charge date and — after a renewal — the end of the period its invoice
 * states.
 */
function monthEndCadenceAgrees(LongRunWorld $world, string $what, ?BillingInvoice $invoice = null): void
{
    $mollieNext = $world->nextChargeAt()?->toDateString();
    $billable = $world->billable->fresh();

    expect($mollieNext)->not->toBeNull("{$what}: Mollie has no next charge")
        ->and($billable->nextBillingDate()?->toDateString())->toBe($mollieNext, "{$what}: the package's period ends on another day than Mollie charges")
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($mollieNext, "{$what}: the portal shows another next charge than Mollie's");

    if ($invoice !== null) {
        expect($invoice->period_end->toDateString())->toBe($mollieNext, "{$what}: the invoice states a period that ends before or after Mollie's next charge");
    }
}

function monthEndCadenceRenew(LongRunWorld $world, string $gross, array &$charged, bool $agrees = true): BillingInvoice
{
    $payment = longRunRenew($world);

    expect($payment)->not->toBeNull('Mollie created no charge');

    $charged[] = substr((string) $payment->createdAt, 0, 10);
    $invoice = longRunAssertRenewal($world, $payment, $gross);

    // The period starts on the day Mollie created the charge (cycleStart()).
    expect($invoice->period_start->toDateString())->toBe(end($charged))
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe(end($charged));

    if ($agrees) {
        monthEndCadenceAgrees($world, 'renewal of '.end($charged), $invoice);
    }

    return $invoice;
}

/**
 * The next-charge dates the package gave Mollie, in order — by a PATCH where Mollie still
 * takes one, by replacing the subscription where it has been charged (Mollie refuses a new
 * start date then; MollieSubscriptionReplacer).
 *
 * @return list<string>
 */
function monthEndPinnedDates(LongRunWorld $world): array
{
    return $world->moves;
}

/**
 * The dates the package has to give Mollie under each answer. Since 17 November 2026 the
 * package first asks Mollie for its own next date and moves it only where that is not the
 * anchor day, so the two answers no longer need the same dates: under "clamp" Mollie
 * returns to its start day by itself, and a date is given only where the start day itself
 * was shortened (a start on February's last day); under "drift" after every month shorter
 * than the anchor day.
 *
 * @param  list<string>  $drift
 * @param  list<string>  $clamp
 * @return list<string>
 */
function monthEndExpectedPins(string $cadence, array $drift, array $clamp): array
{
    return $cadence === 'clamp' ? $clamp : $drift;
}

it('keeps the day of a cycle on the 29th, 30th or 31st for fourteen months, through a leap February', function (string $cadence, string $signup, array $expectedCharges, array $expectedPins, array $clampPins): void {
    $world = longRunStart('monthly', $signup, '34.51');
    $world->cadence = $cadence;

    monthEndCadenceAgrees($world, 'after the signup');

    $charged = [];

    foreach ($expectedCharges as $n) {
        monthEndCadenceRenew($world, '34.51', $charged);
    }

    expect($charged)->toBe($expectedCharges)
        // Sent only where Mollie's own next date is not the anchor day: under "drift" after
        // every shorter month; under "clamp" never, because the start date sent at the
        // signup is already on the anchor day.
        ->and(monthEndPinnedDates($world))->toBe(monthEndExpectedPins($cadence, $expectedPins, $clampPins));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences())->with([
    // Signup 31 Dec 2027 → first period to 31 Jan 2028, the start date sent to Mollie.
    'the 31st' => ['2027-12-31 10:00:00',
        ['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30', '2028-05-31', '2028-06-30', '2028-07-31', '2028-08-31', '2028-09-30', '2028-10-31', '2028-11-30', '2028-12-31', '2029-01-31', '2029-02-28', '2029-03-31'],
        ['2028-03-31', '2028-05-31', '2028-07-31', '2028-10-31', '2028-12-31', '2029-03-31'],
        [],
    ],
    'the 30th' => ['2027-12-30 10:00:00',
        ['2028-01-30', '2028-02-29', '2028-03-30', '2028-04-30', '2028-05-30', '2028-06-30', '2028-07-30', '2028-08-30', '2028-09-30', '2028-10-30', '2028-11-30', '2028-12-30', '2029-01-30', '2029-02-28', '2029-03-30'],
        ['2028-03-30', '2029-03-30'],
        [],
    ],
    // A leap February has the 29th: nothing to send until the common one.
    'the 29th' => ['2027-12-29 10:00:00',
        ['2028-01-29', '2028-02-29', '2028-03-29', '2028-04-29', '2028-05-29', '2028-06-29', '2028-07-29', '2028-08-29', '2028-09-29', '2028-10-29', '2028-11-29', '2028-12-29', '2029-01-29', '2029-02-28', '2029-03-29'],
        ['2029-03-29'],
        [],
    ],
]);

it('keeps a signup on 31 January on the 31st although its first charge is 28 February', function (string $cadence): void {
    // The start date sent is the end of the first period, 28 February — a day Mollie
    // would keep under either answer. The anchor is the signup's day.
    $world = longRunStart('monthly', '2027-01-31 10:00:00', '34.51');
    $world->cadence = $cadence;

    expect($world->cadenceStart->toDateString())->toBe('2027-02-28');

    $charged = [];

    foreach (range(1, 5) as $n) {
        monthEndCadenceRenew($world, '34.51', $charged);
    }

    // Under "clamp" once: the start date was February's last day, so Mollie would return
    // to the 28th. From 31 March on it keeps the 31st by itself.
    expect($charged)->toBe(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30'])
        ->and(monthEndPinnedDates($world))->toBe(monthEndExpectedPins($cadence, ['2027-03-31', '2027-05-31', '2027-07-31'], ['2027-03-31']));
})->with(monthEndCadences());

it('prorates inside the short and the long period of a cycle on the 31st', function (string $cadence): void {
    $world = longRunStart('monthly', '2026-12-31 10:00:00', '34.51');
    $world->cadence = $cadence;

    expect($world->cadenceStart->toDateString())->toBe('2027-01-31');

    $charged = [];
    monthEndCadenceRenew($world, '34.51', $charged);

    // ── An extra seat in the short period 31 Jan → 28 Feb (28 days) ──────────────
    // 15 Feb: 13 days left. 5.00 × round(13/28, 6) = 500 × 0.464286 = 232.14 → 2.32 net;
    // VAT 0.44 → 2.76.
    Carbon::setTestNow(Carbon::parse('2027-02-15 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['seats' => 3]);

    // Plan 29.00 + seat 5.00 = 34.00 net; VAT per line 5.51 + 0.95 → 40.46.
    expect($world->heldValue)->toBe('40.46');

    $seat = longRunSettleSideCharge($world, '2027-02-15 09:05:00');

    expect((int) $seat->amount_net)->toBe(232)
        ->and((int) $seat->amount_gross)->toBe(276)
        ->and($seat->period_end->toDateString())->toBe('2027-02-28');
    monthEndCadenceAgrees($world, 'after the seat');

    monthEndCadenceRenew($world, '40.46', $charged);

    // ── An addon in the long period 28 Feb → 31 Mar (31 days) ────────────────────
    // 14 Mar: 17 days left. 15.00 × round(17/31, 6) = 1500 × 0.548387 = 822.58 → 8.23 net;
    // VAT 1.56 → 9.79. With the period wrongly ending on 28 Mar it would be 14/28 → 7.50.
    Carbon::setTestNow(Carbon::parse('2027-03-14 09:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['addons' => ['sso']]);

    // 29.00 + 5.00 + 15.00 = 49.00 net; VAT 5.51 + 0.95 + 2.85 → 58.31.
    expect($world->heldValue)->toBe('58.31');

    $addon = longRunSettleSideCharge($world, '2027-03-14 09:05:00');

    expect((int) $addon->amount_net)->toBe(823)
        ->and((int) $addon->amount_gross)->toBe(979)
        ->and($addon->period_end->toDateString())->toBe('2027-03-31');
    monthEndCadenceAgrees($world, 'after the addon');

    foreach (range(1, 5) as $n) {
        monthEndCadenceRenew($world, '58.31', $charged);
    }

    expect($charged)->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31'])
        // The seat and the addon moved the amount only.
        ->and(monthEndPinnedDates($world))->toBe(monthEndExpectedPins($cadence, ['2027-03-31', '2027-05-31', '2027-07-31'], []));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences());

it('keeps a yearly subscription from 29 February on the 29th in leap years', function (string $cadence): void {
    // Pro yearly 290.00 + 19 % = 345.10. The start date sent is 28 Feb 2029.
    $world = longRunStart('yearly', '2028-02-29 12:00:00', '345.10');
    $world->cadence = $cadence;

    $charged = [];

    foreach (range(1, 5) as $n) {
        monthEndCadenceRenew($world, '345.10', $charged);
    }

    expect($charged)->toBe(['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29', '2033-02-28'])
        // Only the year whose February has the 29th needs the date sent.
        ->and(monthEndPinnedDates($world))->toBe(['2032-02-29']);
})->with(monthEndCadences());

it('turns deferred periods over on the anchor day and returns the charge on it', function (string $cadence): void {
    app(CouponService::class)->create([
        'code' => 'FREE3',
        'name' => 'Three periods free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    // Signed up on 31 Dec with three periods free — 31 Dec, 31 Jan, 28 Feb: Mollie holds
    // the full price, deferred to the fourth charge, 31 March. (Until 17 November 2026 the
    // periods were 30-day blocks: 31 Dec + 3 × 30 + 2 days = 2 April, and the subscription
    // was charged on the 2nd from then on.)
    $world = longRunStartMandateOnly('monthly', '2026-12-31 10:00:00', 'FREE3');
    $world->cadence = $cadence;

    $firstCharge = $world->nextChargeAt();
    expect($firstCharge->toDateString())->toBe('2027-03-31');

    $anchors = [];
    for ($day = Carbon::parse('2027-01-01 02:00:00', 'UTC'); $day->lessThan($firstCharge); $day = $day->copy()->addDay()) {
        longRunNight($world, $day->toDateTimeString());
        $anchors[$world->billable->getBillingPeriodStartsAt()->toDateString()] = true;

        // While deferred, the portal shows Mollie's deferred charge, not the period end.
        expect(app(NextChargeSummary::class)->for($world->billable->fresh())['date']?->toDateString())->toBe('2027-03-31');
    }

    // On the 31st, through February — not 28 Feb → 28 Mar. The period the returning
    // charge opens is the charge's own: the night before it does not open one.
    expect(array_keys($anchors))->toBe(['2026-12-31', '2027-01-31', '2027-02-28']);

    $world->lastInvoice = null;
    $charged = [];

    foreach (range(1, 4) as $n) {
        monthEndCadenceRenew($world, '34.51', $charged);
    }

    // The charge that ends the free time is on the anchor day, and the cadence stays on
    // it: April's 30th, then the 31st again — sent where Mollie could count on from the
    // 30th.
    expect($charged)->toBe(['2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30'])
        ->and(monthEndPinnedDates($world))->toBe(monthEndExpectedPins($cadence, ['2027-05-31', '2027-07-31'], []))
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['billing_anchor']['day'] ?? null)->toBe(31);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences());

it('ends or continues a gift with paid extras on the anchor day of a cycle on the 31st', function (string $cadence, string $onExpiry): void {
    // Pro given on 31 Dec 2026 for 135 days (to 15 May 2027); SSO bought on 31 Jan, so
    // the Mollie subscription's first period is 31 Jan → 28 Feb and its day is the 31st.
    // Waived: SSO 15.00 + 19 % = 17.85. Full: 44.00 net, per line 34.51 + 17.85 = 52.36.
    $world = longRunStartGifted('2026-12-31 10:00:00', 135, $onExpiry, '2027-01-31 10:00:00', ['sso'], 2);
    $world->cadence = $cadence;

    expect($world->heldValue)->toBe('17.85');
    monthEndCadenceAgrees($world, 'after the purchase');

    $charged = [];

    // 28 Feb, 31 Mar, 30 Apr: created before the gift's end, plan-free.
    monthEndCadenceRenew($world, '17.85', $charged);
    monthEndCadenceRenew($world, '17.85', $charged);

    if ($onExpiry === 'end') {
        // The 30 Apr renewal buys the last covered period: its next charge, 31 May, lies
        // after the gift. The subscription ends with the period it paid for — 31 May.
        $last = monthEndCadenceRenew($world, '17.85', $charged, agrees: false);

        expect($last->period_end->toDateString())->toBe('2027-05-31')
            ->and($world->cancelled)->toBeTrue()
            ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
            ->and($world->billable->getBillingSubscriptionEndsAt()->toDateString())->toBe('2027-05-31')
            ->and(longRunRenew($world))->toBeNull()
            ->and($charged)->toBe(['2027-02-28', '2027-03-31', '2027-04-30'])
            // No date for a subscription that is ending.
            ->and(monthEndPinnedDates($world))->toBe(['2027-03-31']);

        return;
    }

    monthEndCadenceRenew($world, '17.85', $charged);

    // From 31 May the plan is charged.
    foreach (range(1, 3) as $n) {
        monthEndCadenceRenew($world, '52.36', $charged);
    }

    expect($charged)->toBe(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31'])
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(monthEndPinnedDates($world))->toBe(monthEndExpectedPins($cadence, ['2027-03-31', '2027-05-31', '2027-07-31'], ['2027-03-31']));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences())->with(['end', 'continue_paid']);

it('books every period from Mollie\'s own charges when the date could not be sent', function (string $cadence, array $expectedCharges): void {
    // Mollie refuses every PATCH: the package is then on Mollie's own rule and has to
    // follow it without losing or doubling a day.
    $world = longRunStart('monthly', '2026-12-31 10:00:00', '34.51');
    $world->cadence = $cadence;
    $world->refuse[UpdateSubscriptionRequest::class] = PHP_INT_MAX;

    $charged = [];

    foreach ($expectedCharges as $n) {
        $payment = longRunRenew($world);
        $charged[] = substr((string) $payment->createdAt, 0, 10);

        // Where Mollie charged before the anchor day, the period that was to end on the
        // 31st is followed by one starting on the 28th — up to three days, once; the
        // period then starts on the charge, and nothing accumulates.
        $invoice = longRunAssertRenewal($world, $payment, '34.51', gapToleranceDays: 3);

        expect($invoice->period_start->toDateString())->toBe(end($charged))
            ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);
    }

    expect($charged)->toBe($expectedCharges)
        ->and(longRunInvoices($world))->toHaveCount(count($expectedCharges) + 1);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    // Clamping from 31 Jan is the anchor day already.
    'clamp' => ['clamp', ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']],
    // Drifting, Mollie stays on the 28th, and so does the package from the first charge on it.
    'drift' => ['drift', ['2027-01-31', '2027-02-28', '2027-03-28', '2027-04-28', '2027-05-28']],
]);

it('keeps the anchor day through a plan change on or inside a month-end period', function (string $cadence, string $changeAt, int $renewalsBefore, string $periodEnd, int $chargeNet, int $chargeGross, int $creditNet): void {
    // Pro → Team (59.00, 19 % → 70.21) without an interval change: the period runs on,
    // so the day it is charged on does not move — not even when the change is made on
    // 28 February, the shortened day of a cycle on the 31st.
    lifecycleCatalogExtras();

    $world = longRunStart('monthly', '2026-12-31 10:00:00', '34.51');
    $world->cadence = $cadence;

    $charged = [];

    foreach (range(1, $renewalsBefore) as $n) {
        monthEndCadenceRenew($world, '34.51', $charged);
    }

    $periodStart = $world->billable->fresh()->getBillingPeriodStartsAt()->toDateString();

    Carbon::setTestNow(Carbon::parse($changeAt, 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'team', 'interval' => 'monthly']);

    $change = longRunSettleSideCharge($world, Carbon::parse($changeAt, 'UTC')->addMinutes(5)->toDateTimeString());

    expect($world->heldValue)->toBe('70.21')
        ->and($world->billable->getBillingSubscriptionPlanCode())->toBe('team')
        // The period is the one that was running, on its anchor day.
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe($periodStart)
        ->and($change->period_end->toDateString())->toBe($periodEnd)
        // Team for the rest of the period on the charge, Pro's unused share credited.
        ->and((int) $change->amount_net)->toBe($chargeNet)
        ->and((int) $change->amount_gross)->toBe($chargeGross)
        ->and(abs((int) BillingInvoice::query()->where('invoice_kind', \GraystackIT\MollieBilling\Enums\InvoiceKind::Refund)->latest('id')->first()?->amount_net))->toBe($creditNet);
    monthEndCadenceAgrees($world, 'after the plan change on '.$changeAt);

    foreach (range(1, 3) as $n) {
        monthEndCadenceRenew($world, '70.21', $charged);
    }

    // The cycle never left the 31st.
    expect($charged)->toBe(array_slice(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], 0, $renewalsBefore + 3));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences())->with([
    // On the day the period 31 Jan → 28 Feb began: 28 of 28 days. Team 59.00 + VAT 11.21
    // = 70.21 charged, Pro 29.00 credited.
    'on 31 January' => ['2027-01-31 12:00:00', 1, '2027-02-28', 5900, 7021, 2900],
    // On the day the period 28 Feb → 31 Mar began: 31 of 31 days.
    'on 28 February' => ['2027-02-28 12:00:00', 2, '2027-03-31', 5900, 7021, 2900],
    // Inside it, 14 March: 17 of 31 days (with the period wrongly ending on 28 March it
    // would be 14 of 28). 59.00 × 0.548387 = 32.35 + VAT 6.15 = 38.50 charged;
    // 29.00 × 0.548387 = 15.90 credited.
    'on 14 March' => ['2027-03-14 12:00:00', 2, '2027-03-31', 3235, 3850, 1590],
]);

it('starts a new anchor day with an interval change on 28 February', function (string $cadence): void {
    // Monthly → yearly on 28 February, inside the period 28 Feb → 31 Mar of a cycle on the
    // 31st. A new period begins: its day is the day it begins, 28 February — Mollie is
    // sent 28 Feb 2028, and the yearly cycle stays on the 28th, the leap year included.
    $world = longRunStart('monthly', '2026-12-31 10:00:00', '34.51');
    $world->cadence = $cadence;

    $charged = [];
    monthEndCadenceRenew($world, '34.51', $charged);
    monthEndCadenceRenew($world, '34.51', $charged);

    Carbon::setTestNow(Carbon::parse('2027-02-28 12:00:00', 'UTC'));
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['plan_code' => 'pro', 'interval' => 'yearly']);

    $switch = longRunSettleSideCharge($world, '2027-02-28 12:05:00');
    $world->billable = $world->billable->fresh();

    // A full year (290.00) is charged; the month just paid is credited in full, 31 of 31
    // days unused (29.00).
    $credit = BillingInvoice::query()->where('invoice_kind', \GraystackIT\MollieBilling\Enums\InvoiceKind::Refund)->sole();

    expect($world->billable->getBillingSubscriptionInterval())->toBe('yearly')
        ->and($world->heldValue)->toBe('345.10')
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-02-28')
        ->and($switch->period_end->toDateString())->toBe('2028-02-28')
        ->and((int) $switch->amount_net)->toBe(29000)
        ->and(abs((int) $credit->amount_net))->toBe(2900);
    monthEndCadenceAgrees($world, 'after the interval change');

    $world->lastInvoice = $switch;

    foreach (range(1, 2) as $n) {
        monthEndCadenceRenew($world, '345.10', $charged);
    }

    expect($charged)->toBe(['2027-01-31', '2027-02-28', '2028-02-28', '2029-02-28']);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences());

/**
 * A customer on the free plan since 10 January who upgrades to Pro on 31 January at
 * 10:00, by one of the three ways the package has.
 */
function monthEndUpgradeFromFree(string $way, int $year): LongRunWorld
{
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]]],
    ]);

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_longrun');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    if ($way === 'gift extras') {
        // Given Pro on 10 January for a year; SSO bought on 31 January.
        return longRunStartGifted("{$year}-01-10 10:00:00", 365, 'end', "{$year}-01-31 10:00:00", ['sso'], 2);
    }

    Carbon::setTestNow(Carbon::parse("{$year}-01-10 10:00:00", 'UTC'));

    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Month End Upgrade Co', 'email' => uniqid('me-upgrade-').'@x.test', 'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    app(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class)->handle($billable->fresh(), 'free', 'monthly');

    $world = LongRunWorld::$current = new LongRunWorld($billable->fresh(), 'monthly');

    if (str_starts_with($way, 'code')) {
        app(CouponService::class)->create([
            'code' => 'FREE1', 'name' => 'Periods free', 'type' => CouponType::Recurring,
            'discount_type' => DiscountType::Percentage, 'discount_value' => 100,
            'discount_periods' => $way === 'code, two periods' ? 2 : 1,
        ]);
    }

    Carbon::setTestNow(Carbon::parse("{$year}-01-31 10:00:00", 'UTC'));

    app(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class)->handle($world->billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'addon_codes' => [],
        'extra_seats' => 0,
        // Pro 29.00 + 19 % = 34.51 — or nothing, under the code.
        'amount_gross' => $way === 'paid' ? 3451 : 0,
        'coupon_code' => $way === 'paid' ? null : 'FREE1',
    ]);

    $world->lastInvoice = longRunSettleSideCharge($world, "{$year}-01-31 10:05:00");

    return $world;
}

it('keeps an upgrade from the free plan on 31 January on the 31st, whichever way it was made', function (string $cadence, string $way, int $year): void {
    $world = monthEndUpgradeFromFree($way, $year);
    $world->cadence = $cadence;

    $february = $year === 2028 ? "{$year}-02-29" : "{$year}-02-28";
    $billable = $world->billable->fresh();

    // A code over two periods covers the upgrade's own period and February's: the first
    // charge is the third one on the cycle, 31 March. Every other way charges at the end of
    // the upgrade's own period, February's last day. All of them stay on the 31st. (Until
    // 17 November 2026 the code's periods were 30-day blocks — first charge 2 or 3 April,
    // and the cadence moved to that day.)
    $firstCharge = $way === 'code, two periods' ? "{$year}-03-31" : $february;
    $expectedCharges = $way === 'code, two periods'
        ? ["{$year}-03-31", "{$year}-04-30", "{$year}-05-31", "{$year}-06-30"]
        : [$february, "{$year}-03-31", "{$year}-04-30", "{$year}-05-31"];
    // Sent where one month after a charge is not the anchor day: after a charge on February's
    // last day or on 30 April / 30 June.
    // Under "clamp" only where the start date itself was February's last day.
    $expectedPins = monthEndExpectedPins(
        $cadence,
        $way === 'code, two periods' ? ["{$year}-05-31", "{$year}-07-31"] : ["{$year}-03-31", "{$year}-05-31"],
        $way === 'code, two periods' ? [] : ["{$year}-03-31"],
    );
    $expectedAnchor = 31;

    expect($billable->getBillingSubscriptionSource())->toBe('mollie')
        ->and($billable->getBillingPeriodStartsAt()->toDateString())->toBe("{$year}-01-31")
        ->and($billable->nextBillingDate()->toDateString())->toBe($february)
        ->and($world->cadenceStart->toDateString())->toBe($firstCharge)
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($firstCharge);

    // What the upgrade itself sent is not what this test is about.
    $patchesBefore = count($world->patches);
    $movesBefore = count($world->moves);

    if ($firstCharge !== $february) {
        // The covered period nobody is charged for turns over on February's last day, and
        // ends on the 31st: 31 Jan → February's last day → 31 March, not the 28th or 29th.
        for ($night = Carbon::parse("{$year}-02-01 02:00:00", 'UTC'); $night->lessThan(Carbon::parse($firstCharge, 'UTC')); $night = $night->copy()->addDay()) {
            longRunNight($world, $night->toDateTimeString());
        }

        expect($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe($february)
            ->and($world->billable->nextBillingDate()->toDateString())->toBe("{$year}-03-31")
            ->and(app(NextChargeSummary::class)->for($world->billable->fresh())['date']?->toDateString())->toBe("{$year}-03-31");
        $world->lastInvoice = null;
    } else {
        monthEndCadenceAgrees($world, 'after the upgrade');
    }

    $gross = $way === 'gift extras' ? '17.85' : '34.51';
    $charged = [];

    foreach (range(1, 4) as $n) {
        monthEndCadenceRenew($world, $gross, $charged);
    }

    $world->patches = array_slice($world->patches, $patchesBefore);
    $world->moves = array_slice($world->moves, $movesBefore);

    expect($charged)->toBe($expectedCharges)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()['billing_anchor']['day'] ?? null)->toBe($expectedAnchor)
        ->and(monthEndPinnedDates($world))->toBe($expectedPins);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(monthEndCadences())->with(['paid', 'code, one period', 'code, two periods', 'gift extras'])->with([2027, 2028]);

it('does not send the day of a renewal as the next charge when a one-period code ends with it', function (string $cadence): void {
    // Free plan, upgraded on 10 January under a 100 % code for one period: the upgrade's
    // own period is free, Mollie charges 34.51 from 10 February. The renewal that ends the
    // code resets Mollie's amount — and must leave the cadence alone: the next charge is
    // 10 March, not a second one on 10 February.
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => ['Tokens' => 10]]],
    ]);
    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_longrun');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);

    Carbon::setTestNow(Carbon::parse('2027-01-02 10:00:00', 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Ten Co', 'email' => uniqid('ten-').'@x.test', 'billing_country' => 'DE',
    ]);
    $billable->forceFill(['mollie_customer_id' => 'cst_longrun'])->save();
    app(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class)->handle($billable->fresh(), 'free', 'monthly');
    $world = LongRunWorld::$current = new LongRunWorld($billable->fresh(), 'monthly');
    $world->cadence = $cadence;

    app(CouponService::class)->create([
        'code' => 'FREE1', 'name' => 'One period free', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => 100, 'discount_periods' => 1,
    ]);

    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    app(\GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie::class)->handle($world->billable->fresh(), [
        'plan_code' => 'pro', 'interval' => 'monthly', 'addon_codes' => [], 'extra_seats' => 0,
        'amount_gross' => 0, 'coupon_code' => 'FREE1',
    ]);
    $world->lastInvoice = longRunSettleSideCharge($world, '2027-01-10 10:05:00');

    expect($world->cadenceStart->toDateString())->toBe('2027-02-10');

    $charged = [];

    foreach (range(1, 3) as $n) {
        monthEndCadenceRenew($world, '34.51', $charged);
    }

    expect($charged)->toBe(['2027-02-10', '2027-03-10', '2027-04-10'])
        // Nothing sent that moves the cadence onto a day already charged.
        ->and(collect($world->patches)->pluck('startDate')->filter(fn (?string $d): bool => $d !== null && $d <= '2027-02-10')->values()->all())->toBe([]);
})->with(monthEndCadences());

it('never names today as the date a charge returns to', function (): void {
    // A period from 10 January 10:00; it is 10 February 06:00 — Mollie has created today's
    // charge, the webhook has not moved the anchor yet. The period boundary (10:00) is
    // still "in the future", but a start date of today is a second charge today: the
    // regular date to return to is 10 March.
    Carbon::setTestNow(Carbon::parse('2027-01-10 10:00:00', 'UTC'));
    $billable = \GraystackIT\MollieBilling\Testing\TestBillable::create([
        'name' => 'Today Co', 'email' => uniqid('today-').'@x.test', 'billing_country' => 'DE',
    ]);
    $billable->forceFill([
        'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => 'monthly',
        'subscription_period_starts_at' => Carbon::now(),
    ])->save();

    Carbon::setTestNow(Carbon::parse('2027-02-10 06:00:00', 'UTC'));
    $billable = $billable->fresh();

    expect(\GraystackIT\MollieBilling\Support\ChargeDeferral::undeferredNextChargeDate($billable)?->toDateString())->toBe('2027-03-10')
        ->and(\GraystackIT\MollieBilling\Support\ChargeDeferral::regularNextBillingDate($billable)?->toDateString())->toBe('2027-03-10');

    // The day before, the boundary is tomorrow and stays the answer.
    Carbon::setTestNow(Carbon::parse('2027-02-09 23:00:00', 'UTC'));

    expect(\GraystackIT\MollieBilling\Support\ChargeDeferral::undeferredNextChargeDate($billable->fresh())?->toDateString())->toBe('2027-02-10');
});
