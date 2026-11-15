<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * SEPA Direct Debit: Mollie creates the charge on the cycle date and the money settles
 * days later — a different number of days every month.
 *
 * The period a recurring payment buys is the cycle it was created for. Anchored on
 * `paidAt` instead, the period started whenever the bank got round to it: a month that
 * settled faster than the one before overlapped it (the customer paid twice for those
 * days on paper), a slower one left a hole, and `nextBillingDate()` named a day up to a
 * week after the one Mollie actually charges on — which is the date the overage pass, the
 * prorata window and the coupon-expiry check all work from.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the period on the cycle date while SEPA settlement times vary', function (): void {
    $world = longRunStart('monthly', '2027-01-10 10:00:00', '34.51');

    // Days until the money arrives, month by month — sometimes shorter than the month before.
    $settles = ['3 days', '5 days', '1 day', '0 days', '4 days', '2 days', '5 days', '1 day', '3 days', '0 days', '5 days', '2 days'];

    foreach ($settles as $n => $settlesAfter) {
        $payment = longRunRenew($world, $settlesAfter);
        $invoice = longRunAssertRenewal($world, $payment, '34.51');

        $cycle = CarbonImmutable::parse($payment->createdAt, 'UTC');

        // The period is the cycle Mollie charged for, whatever day the bank settled.
        expect($invoice->period_start->toIso8601String())->toBe($cycle->toIso8601String(), 'period start is not the cycle date, month '.($n + 1))
            ->and($world->billable->nextBillingDate()->toDateString())->toBe($cycle->addMonthNoOverflow()->toDateString());
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});
