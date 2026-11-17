<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * Recurring coupons over a long run. A discount has to end exactly where it was promised
 * to: the reset has to reach Mollie before the first full-price charge is created, the
 * invoice of every period has to state what that period really cost, and the redemption
 * trail has to count the same periods the customer saw.
 *
 * Pro is 29.00 net. 20 % off is 23.20 net; per line at 19 % that is 34.51 − 6.90 = 27.61.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

function longRunDiscountLines(\GraystackIT\MollieBilling\Models\BillingInvoice $invoice): int
{
    return collect((array) $invoice->line_items)->where('kind', 'coupon')->count();
}

it('discounts exactly three periods of a three-period coupon and returns to full price before the fourth', function (string $settlesAfter): void {
    app(CouponService::class)->create([
        'code' => 'TWENTY3',
        'name' => '20 % for three periods',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    // Period 1 is the checkout itself.
    $world = longRunStart('monthly', '2027-11-10 10:00:00', '27.61', ['coupon_code' => 'TWENTY3']);

    expect(longRunDiscountLines($world->lastInvoice))->toBe(1)
        ->and($world->heldValue)->toBe('27.61');

    // Twelve renewals from 10 December — through February, where a 30-day reading of
    // "three periods" is a day short of the calendar.
    foreach (range(1, 12) as $n) {
        $payment = longRunRenew($world, $settlesAfter);
        $discounted = $n <= 2;

        $invoice = longRunAssertRenewal($world, $payment, $discounted ? '27.61' : '34.51');

        expect(longRunDiscountLines($invoice))->toBe($discounted ? 1 : 0, "period ".($n + 1).' carries the wrong number of discount lines');

        if ($n === 2) {
            // The third discounted period has just been booked: Mollie must already hold
            // the full price, well before it creates the fourth charge.
            expect($world->heldValue)->toBe('34.51')
                ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
        }
    }

    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe(3)
        ->and($world->patches)->toHaveCount(1);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(['card' => ['90 seconds'], 'SEPA, four days' => ['4 days']]);

it('ends a date-limited coupon with the last charge created before its end', function (string $validUntil, string $settlesAfter, int $discountedRenewals): void {
    app(CouponService::class)->create([
        'code' => 'UNTIL',
        'name' => '20 % until a date',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'valid_until' => Carbon::parse($validUntil, 'UTC'),
        // Far more periods than the date allows: the date is what ends this one.
        'max_redemptions_per_billable' => 24,
    ]);

    $world = longRunStart('monthly', '2027-01-10 10:00:00', '27.61', ['coupon_code' => 'UNTIL']);

    // Cycles on the 10th. A charge Mollie created while the coupon was valid is a
    // discounted period, whenever its money arrives; the first one created after the
    // end is the first at full price.
    foreach (range(1, 8) as $n) {
        $payment = longRunRenew($world, $settlesAfter);
        $discounted = $n <= $discountedRenewals;

        $invoice = longRunAssertRenewal($world, $payment, $discounted ? '27.61' : '34.51');

        expect(longRunDiscountLines($invoice))->toBe($discounted ? 1 : 0, 'renewal '.$n.' carries the wrong number of discount lines');
    }

    // The checkout plus every discounted renewal, and nothing after.
    expect(CouponRedemption::query()->where('discount_amount_net', '>', 0)->count())->toBe(1 + $discountedRenewals);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    'ends mid-period, card' => ['2027-03-25 00:00:00', '90 seconds', 2],
    'ends mid-period, SEPA' => ['2027-03-25 00:00:00', '4 days', 2],
    // The charge of 10 March was created under the coupon and settles after it ended.
    'ends between a charge and its settlement' => ['2027-03-12 00:00:00', '5 days', 2],
    // The coupon ends between "one month after the last settlement" and nothing else:
    // 10 April is past it, so the reset has to be in place by then.
    'ends just before a cycle' => ['2027-04-09 00:00:00', '3 days', 2],
]);

it('charges nothing for three fully covered periods, keeps the periods turning, then charges the full price', function (): void {
    app(CouponService::class)->create([
        'code' => 'FREE3',
        'name' => 'Three periods free',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
        'max_redemptions_per_billable' => 3,
    ]);

    $world = longRunStartMandateOnly('monthly', '2027-01-10 10:00:00', 'FREE3');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        // Mollie cannot charge 0: it holds the full price, deferred past the coupon.
        ->and($world->heldValue)->toBe('34.51');

    expect($world->billable->remainingBillingQuota('Tokens'))->toBe(100, 'quota after signup');

    // Three periods — 10 Jan, 10 Feb, 10 Mar — and Mollie collects again on 10 April.
    $firstCharge = $world->nextChargeAt();
    expect($firstCharge->toDateString())->toBe('2027-04-10');

    // No charge, so no webhook: the nightly pass turns the periods over and refills.
    $anchors = [];
    for ($day = Carbon::parse('2027-01-11 02:00:00', 'UTC'); $day->lessThan($firstCharge); $day = $day->copy()->addDay()) {
        if ($day->day === 5) {
            $world->billable->fresh()->recordBillingUsage('Tokens', 40);
        }

        longRunNight($world, $day->toDateTimeString());
        $anchors[$world->billable->getBillingPeriodStartsAt()->toDateString()] = true;

        // Each free period is refilled when it begins.
        if ($day->day === 11 && $day->month < 4) {
            expect($world->billable->remainingBillingQuota('Tokens'))->toBe(100, 'quota not refilled for the period starting '.$day->toDateString());
        }
    }

    // The period the returning charge opens is the charge's own.
    expect(array_keys($anchors))->toBe(['2027-01-10', '2027-02-10', '2027-03-10'])
        ->and(longRunInvoices($world))->toHaveCount(1);

    // From here on it is an ordinary paying subscription.
    $world->lastInvoice = null;

    foreach (['2027-04-10', '2027-05-10', '2027-06-10', '2027-07-10', '2027-08-10', '2027-09-10'] as $cycle) {
        $payment = longRunRenew($world);

        // The charge that ends the free time is on the billing day, and the cadence stays
        // there, at whatever hour of that day Mollie creates it. (Until 17 November 2026
        // the 12th: 3 × 30 + 2 days.)
        expect(substr($payment->createdAt, 0, 10))->toBe($cycle);

        $invoice = longRunAssertRenewal($world, $payment, '34.51');

        expect(longRunDiscountLines($invoice))->toBe(0)
            ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
