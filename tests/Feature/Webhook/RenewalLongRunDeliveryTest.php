<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * Deliveries that do not arrive the way the happy path assumes — twice, late, in the
 * wrong order, or as a failure followed by a success — in the middle of a long run. None
 * of them may book a period twice, lose one, or leave the run unable to continue.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('books a renewal once however often and however late its webhook is delivered', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 12) as $n) {
        $payment = longRunCreateCharge($world);

        // Every third webhook reaches us three days late.
        $late = $n % 3 === 0;
        longRunDeliver($world, $payment, $late ? Carbon::parse($payment->paidAt)->addDays(3)->toIso8601String() : null);

        $invoice = longRunAssertRenewal($world, $payment, '34.51');

        // Used between the deliveries, so a second refill would show.
        $world->billable->fresh()->recordBillingUsage('Tokens', 30);

        // Mollie re-delivers: straight away, and again a day later.
        longRunDeliver($world, $payment, Carbon::parse($payment->paidAt)->addDays($late ? 3 : 0)->addMinutes(5)->toIso8601String());
        longRunDeliver($world, $payment, Carbon::parse($payment->paidAt)->addDays($late ? 4 : 1)->toIso8601String());

        expect(BillingInvoice::query()->where('mollie_payment_id', $payment->id)->count())->toBe(1)
            ->and($world->billable->getBillingPeriodStartsAt()->toIso8601String())->toBe($invoice->period_start->toIso8601String())
            ->and($world->billable->getBillingPeriodStartsAt()->day)->toBe(15)
            ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(70);
    }

    expect(longRunInvoices($world))->toHaveCount(13);
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('survives two renewals delivered in the wrong order', function (): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 3) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');
    }

    // The May charge's webhook is lost; June's arrives first, May's two days after that.
    $may = longRunCreateCharge($world);
    $june = longRunCreateCharge($world);

    longRunDeliver($world, $june);

    $juneInvoice = BillingInvoice::query()->where('mollie_payment_id', $june->id)->sole();
    longRunAssertDocumentAddsUp($juneInvoice, '34.51', 'June');

    expect($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-06-15');

    $world->billable->fresh()->recordBillingUsage('Tokens', 30);

    longRunDeliver($world, $may, '2027-06-17 08:00:00');

    $mayInvoice = BillingInvoice::query()->where('mollie_payment_id', $may->id)->sole();
    longRunAssertDocumentAddsUp($mayInvoice, '34.51', 'May');

    // May is invoiced for its own month — the money did arrive — but the period does not
    // go back to it and June's quota is not handed out a second time.
    expect($mayInvoice->period_start->toDateString())->toBe('2027-05-15')
        ->and($mayInvoice->period_end->toDateString())->toBe('2027-06-15')
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-06-15')
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(70);

    // And the run goes on from June as if nothing had happened.
    $world->lastInvoice = $juneInvoice;

    foreach (range(1, 6) as $n) {
        longRunAssertRenewal($world, longRunRenew($world), '34.51');
    }

    expect($world->billable->nextBillingDate()->toDateString())->toBe('2028-01-15');
    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('recovers from a failed renewal with the next successful one, without a doubled or invented period', function (string $settlesAfter): void {
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');

    foreach (range(1, 3) as $n) {
        longRunAssertRenewal($world, longRunRenew($world, $settlesAfter), '34.51');
    }

    // May fails.
    $failed = longRunCreateCharge($world, status: 'failed');
    longRunDeliver($world, $failed, '2027-05-16 07:00:00');

    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $failed->id)->exists())->toBeFalse()
        // The period that was paid for is April's; nothing moved.
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-04-15');

    // The failure is re-delivered: still one dunning episode, with its original start.
    $since = $world->billable->getBillingSubscriptionMeta()['past_due_since'] ?? null;
    longRunDeliver($world, $failed, '2027-05-18 07:00:00');
    expect($world->billable->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBe($since);

    // June succeeds.
    $june = longRunRenew($world, $settlesAfter);
    $invoice = BillingInvoice::query()->where('mollie_payment_id', $june->id)->sole();

    longRunAssertDocumentAddsUp($invoice, '34.51', 'June after a failed May');
    longRunAssertSerialFollows($invoice, 'June after a failed May');

    // June's payment buys June. May stays unpaid and is not papered over: no invoice
    // claims it, and the period does not pretend to have started then.
    expect($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($invoice->period_start->toDateString())->toBe('2027-06-15')
        ->and($invoice->period_end->toDateString())->toBe('2027-07-15')
        ->and($world->billable->getBillingPeriodStartsAt()->toDateString())->toBe('2027-06-15')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey('past_due_since')
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey('payment_failure')
        ->and($world->billable->remainingBillingQuota('Tokens'))->toBe(100);

    $world->lastInvoice = $invoice;

    foreach (range(1, 6) as $n) {
        longRunAssertRenewal($world, longRunRenew($world, $settlesAfter), '34.51');
    }

    // Ten paid periods (signup, 3, June, 6 more — May excluded), ten invoices.
    expect(longRunInvoices($world))->toHaveCount(11)
        ->and($world->billable->nextBillingDate()->toDateString())->toBe('2028-01-15');

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with(['card' => ['90 seconds'], 'SEPA' => ['3 days']]);
