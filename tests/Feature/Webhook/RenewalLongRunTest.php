<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * A subscription that simply runs: two years of monthly renewals, and four yearly ones,
 * through the real webhook route. Every renewal has to book exactly what Mollie holds,
 * state the period it bought, follow the one before it without a hole, and take the next
 * serial number.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('runs a monthly subscription through 24 renewals without drifting', function (): void {
    // Pro 29.00 + 19 % = 34.51.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    for ($n = 1; $n <= 24; $n++) {
        $payment = longRunRenew($world);

        expect($payment)->not->toBeNull();
        longRunAssertRenewal($world, $payment, '34.51');

        // Mollie's cadence is the 15th; the local period has to stay on it.
        expect($world->billable->getBillingPeriodStartsAt()->day)->toBe(15)
            ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
            ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);
    }

    expect(longRunInvoices($world))->toHaveCount(25)
        ->and($world->billable->nextBillingDate()->toDateString())->toBe('2029-02-15')
        ->and($world->patches)->toBe([]);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('runs a yearly subscription through four renewals, across a leap year', function (): void {
    // Pro yearly 290.00 + 19 % = 345.10.
    $world = longRunStart('yearly', '2027-03-10 09:30:00', '345.10');

    foreach (['2028-03-10', '2029-03-10', '2030-03-10', '2031-03-10'] as $cycle) {
        $payment = longRunRenew($world);

        expect(substr($payment->createdAt, 0, 10))->toBe($cycle);
        longRunAssertRenewal($world, $payment, '345.10');

        expect($world->billable->remainingBillingQuota('Tokens'))->toBe(1200);
    }

    expect($world->billable->nextBillingDate()->toDateString())->toBe('2032-03-10');

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('keeps a yearly subscription that started on 29 February aligned with its charges', function (): void {
    $world = longRunStart('yearly', '2028-02-29 12:00:00', '345.10');

    // The start date the package sends is the end of the first period, 28 February 2029;
    // the anchor is the 29th, so the leap year 2032 is charged on its 29 February — the
    // package sends that date itself (BillingAnchor, pinNextChargeOnAnchor()).
    foreach (['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'] as $cycle) {
        $payment = longRunRenew($world);

        expect(substr($payment->createdAt, 0, 10))->toBe($cycle);
        longRunAssertRenewal($world, $payment, '345.10');
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('follows Mollie through the month ends when the signup fell on the 29th, 30th or 31st', function (string $signup, string $cadence, array $cycles): void {
    $world = longRunStart('monthly', $signup, '34.51');
    $world->cadence = $cadence;

    foreach ($cycles as $cycle) {
        $payment = longRunRenew($world);

        expect(substr($payment->createdAt, 0, 10))->toBe($cycle);

        // No gap under either cadence: the cycle keeps its anchor day through the short
        // months, and the package sends Mollie that date wherever Mollie's own rule could
        // differ (BillingAnchor, pinNextChargeOnAnchor()).
        longRunAssertRenewal($world, $payment, '34.51');
    }

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([
    'signup 31 Jan → Mollie starts 28 Feb, anchor 31' => ['2027-01-31 10:00:00', 'clamp', ['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31', '2027-08-31', '2027-09-30', '2027-10-31', '2027-11-30', '2027-12-31', '2028-01-31', '2028-02-29', '2028-03-31']],
    'signup 31 Mar → 30 Apr, anchor 31, clamped cadence' => ['2027-03-31 10:00:00', 'clamp', ['2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31', '2027-08-31', '2027-09-30', '2027-10-31', '2027-11-30', '2027-12-31', '2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30']],
    'signup 31 Mar → 30 Apr, anchor 31, drifting cadence' => ['2027-03-31 10:00:00', 'drift', ['2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31', '2027-08-31', '2027-09-30', '2027-10-31', '2027-11-30', '2027-12-31', '2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30']],
    'signup 29 Dec, through a common February' => ['2028-12-29 10:00:00', 'clamp', ['2029-01-29', '2029-02-28', '2029-03-29', '2029-04-29']],
    'signup 30 Dec, drifting through February' => ['2028-12-30 10:00:00', 'drift', ['2029-01-30', '2029-02-28', '2029-03-30', '2029-04-30']],
]);
