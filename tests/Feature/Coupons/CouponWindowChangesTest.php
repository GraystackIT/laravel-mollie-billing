<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\NextChargeSummary;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\ChargeDeferral;
use Illuminate\Support\Facades\Event;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

require_once dirname(__DIR__).'/Webhook/Support/RenewalLongRun.php';

/*
 * A recurring code over N billing periods on the two ways a running subscription can
 * restart its cycle: an interval change and a resubscribe during the grace period.
 * (Plan, seat and addon changes are in CouponWindowInBillingPeriodsTest.)
 *
 * Pro 29.00 / 290.00 net, DE 19 %: 34.51 / 345.10, at 50 % 17.25 / 172.55.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

function changesCoupon(int $value, int $periods): void
{
    app(CouponService::class)->create([
        'code' => 'PERIODS', 'name' => 'Periods', 'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage, 'discount_value' => $value, 'discount_periods' => $periods,
    ]);
}

/** @param list<string> $charged */
function changesRenew(LongRunWorld $world, string $gross, array &$charged): BillingInvoice
{
    $payment = longRunRenew($world);
    expect($payment)->not->toBeNull('Mollie created no charge');
    $charged[] = substr((string) $payment->createdAt, 0, 10);

    $invoice = longRunAssertRenewal($world, $payment, $gross);
    $billable = $world->billable->fresh();

    expect($billable->nextBillingDate()?->toDateString())->toBe($world->nextChargeAt()?->toDateString(), 'the period ends where Mollie charges next')
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($world->nextChargeAt()?->toDateString(), 'the portal names Mollie\'s next charge');

    return $invoice;
}

function changesCouponLines(BillingInvoice $invoice): int
{
    return collect((array) $invoice->line_items)->where('kind', 'coupon')->count();
}

/** Run the nights from `$from` up to `$until`, every night (monthly) or around each boundary (yearly). */
function changesNights(LongRunWorld $world, array $boundaries, string $until): void
{
    foreach ($boundaries as $boundary) {
        foreach ([-1, 0, 1] as $offset) {
            $night = CarbonImmutable::parse($boundary.' 02:00:00', 'UTC')->addDays($offset);

            if ($night->lessThan(CarbonImmutable::parse($until, 'UTC'))) {
                longRunNight($world, $night->toDateTimeString());
            }
        }
    }
}

it('counts a code entered with an interval change in periods of the new interval, from the change', function (
    int $value, string $from, string $to, array $expect,
): void {
    // Signed up on 15 January, paid; the interval is changed with a code over two periods on
    // 25 January. The change starts a new cycle on the 25th.
    changesCoupon($value, 2);
    $world = longRunStart($from, '2027-01-15 10:00:00', $from === 'monthly' ? '34.51' : '345.10');

    Carbon::setTestNow(Carbon::parse('2027-01-25 10:00:00', 'UTC'));
    $requests = count($world->requests);
    app(UpdateSubscription::class)->update($world->billable->fresh(), ['interval' => $to, 'coupon_codes' => ['PERIODS']]);

    $paid = collect(array_slice($world->requests, $requests))->contains(fn (object $r): bool => $r instanceof CreatePaymentRequest);
    expect($paid)->toBe($expect['change_charge'] !== null, 'whether the change is a payment of its own');

    if ($paid) {
        $invoice = longRunSettleSideCharge($world, '2027-01-25 10:05:00');
        expect(changesCouponLines($invoice))->toBe($expect['change_discounted'] ? 1 : 0, 'the code on the change\'s own charge');
    }

    $billable = $world->billable->fresh();

    // The new cycle runs on the 25th, locally and at Mollie.
    expect($billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-01-25')
        ->and($billable->getBillingSubscriptionInterval())->toBe($to)
        ->and($world->cadenceStart?->toDateString())->toBe($expect['mollie_start'])
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($expect['mollie_start'])
        ->and(ChargeDeferral::nextChargeDate($billable)?->toDateString())->toBe($expect['mollie_start']);

    // Free periods turn over on the 25th; then the charges.
    changesNights($world, $expect['free_boundaries'], $expect['mollie_start'].' 06:00:00');
    expect($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    $world->lastInvoice = null;
    $charged = [];
    $lines = [];

    foreach ($expect['charges'] as [$day, $gross]) {
        $lines[] = changesCouponLines(changesRenew($world, $gross, $charged));
    }

    expect($charged)->toBe(array_column($expect['charges'], 0))
        ->and($lines)->toBe(array_map(fn (array $c): int => $c[2], $expect['charges']));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    // The yearly charge of the change is covered in full (the code takes the charge less
    // the monthly credit): nothing is collected now, that year is the first period, the
    // second is free, Mollie collects on 25 January 2029.
    '100 %, monthly to yearly' => [100, 'monthly', 'yearly', [
        'change_charge' => null, 'change_discounted' => false, 'mollie_start' => '2029-01-25',
        'free_boundaries' => ['2028-01-25'],
        'charges' => [['2029-01-25', '345.10', 0], ['2030-01-25', '345.10', 0]],
    ]],
    // The change's charge is discounted (the first period); the renewal a year on is the
    // second; the one after is full.
    '50 %, monthly to yearly' => [50, 'monthly', 'yearly', [
        'change_charge' => true, 'change_discounted' => true, 'mollie_start' => '2028-01-25',
        'free_boundaries' => [],
        'charges' => [['2028-01-25', '172.55', 1], ['2029-01-25', '345.10', 0]],
    ]],
    // The unused year is refunded and exceeds the month's charge: the change costs nothing
    // net, so the code discounts nothing now. Its two periods are the next two months —
    // 25 February and 25 March are free — and Mollie collects on 25 April.
    '100 %, yearly to monthly' => [100, 'yearly', 'monthly', [
        'change_charge' => true, 'change_discounted' => false, 'mollie_start' => '2027-04-25',
        'free_boundaries' => ['2027-02-25', '2027-03-25'],
        'charges' => [['2027-04-25', '34.51', 0], ['2027-05-25', '34.51', 0]],
    ]],
    '50 %, yearly to monthly' => [50, 'yearly', 'monthly', [
        'change_charge' => true, 'change_discounted' => false, 'mollie_start' => '2027-02-25',
        'free_boundaries' => [],
        'charges' => [['2027-02-25', '17.25', 1], ['2027-03-25', '17.25', 1], ['2027-04-25', '34.51', 0]],
    ]],
]);

it('keeps a code\'s periods through a cancellation and a resubscribe in the grace period', function (int $value, array $expect): void {
    // Signed up on 15 January with a code over three periods; cancelled on 20 February,
    // resubscribed on 25 February. The paid (or free) period runs to 15 March.
    changesCoupon($value, 3);
    $world = $value === 100
        ? longRunStartMandateOnly('monthly', '2027-01-15 10:00:00', 'PERIODS')
        : longRunStart('monthly', '2027-01-15 10:00:00', '17.25', ['coupon_code' => 'PERIODS']);

    if ($value === 100) {
        changesNights($world, ['2027-02-15'], '2027-02-20 00:00:00');
    } else {
        $charged = [];
        expect(changesCouponLines(changesRenew($world, '17.25', $charged)))->toBe(1);
    }

    Carbon::setTestNow(Carbon::parse('2027-02-20 10:00:00', 'UTC'));
    app(CancelSubscription::class)->handle($world->billable->fresh());
    expect($world->billable->fresh()->getBillingSubscriptionEndsAt()?->toDateString())->toBe('2027-03-15');

    Carbon::setTestNow(Carbon::parse('2027-02-25 10:00:00', 'UTC'));
    app(ResubscribeSubscription::class)->handle($world->billable->fresh());
    $billable = $world->billable->fresh();

    expect($billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->cadenceStart?->toDateString())->toBe($expect['mollie_start'])
        ->and($world->heldValue)->toBe($expect['held'])
        ->and(app(NextChargeSummary::class)->for($billable)['date']?->toDateString())->toBe($expect['mollie_start']);

    changesNights($world, $expect['free_boundaries'], $expect['mollie_start'].' 06:00:00');

    $world->lastInvoice = null;
    $charged = [];
    $lines = [];

    foreach ($expect['charges'] as [$day, $gross]) {
        $lines[] = changesCouponLines(changesRenew($world, $gross, $charged));
    }

    expect($charged)->toBe(array_column($expect['charges'], 0))
        ->and($lines)->toBe(array_map(fn (array $c): int => $c[2], $expect['charges']));

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    // Free: 15 Jan, 15 Feb, 15 Mar. The resubscribe keeps the code's end: 15 April.
    'fully covering' => [100, [
        'mollie_start' => '2027-04-15', 'held' => '34.51', 'free_boundaries' => ['2027-03-15'],
        'charges' => [['2027-04-15', '34.51', 0], ['2027-05-15', '34.51', 0]],
    ]],
    // Discounted: the checkout, 15 Feb, and 15 March after the resubscribe; 15 April full.
    'half' => [50, [
        'mollie_start' => '2027-03-15', 'held' => '17.25', 'free_boundaries' => [],
        'charges' => [['2027-03-15', '17.25', 1], ['2027-04-15', '34.51', 0]],
    ]],
]);
