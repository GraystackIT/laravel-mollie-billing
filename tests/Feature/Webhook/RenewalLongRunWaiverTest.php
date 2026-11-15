<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\PaymentAmountMismatch;
use GraystackIT\MollieBilling\Services\Billing\AdminExtensionService;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * A plan waiver over a long run: the plan is free, the extras are paid.
 *
 * Pro is 29.00 net and free while the waiver runs; SSO is 15.00 net and always paid.
 * Waived: 15.00 + 19 % = 17.85. Not waived: 44.00 net, per line 34.51 + 17.85 = 52.36.
 */

beforeEach(function (): void {
    longRunBoot();
    Event::fake([PaymentAmountMismatch::class]);
});

afterEach(fn () => Carbon::setTestNow());

function longRunWaiverLine(\GraystackIT\MollieBilling\Models\BillingInvoice $invoice): ?array
{
    return collect((array) $invoice->line_items)->firstWhere('kind', 'coupon');
}

it('ends a gifted subscription with the gift: extras charged while it runs, nothing after', function (): void {
    // Given Pro on 10 January for 90 days (until 10 April); buys SSO on 20 January.
    $world = longRunStartGifted('2027-01-10 10:00:00', 90, 'end', '2027-01-20 10:00:00', ['sso'], 2);

    $giftEnds = $world->billable->getBillingSubscriptionEndsAt() ?? Carbon::parse('2027-04-10 10:00:00', 'UTC');

    expect($world->lastInvoice->amount_gross)->toBe(1785)
        ->and($world->heldValue)->toBe('17.85');

    $charged = [];

    // Far more cycles than the gift has: after it ends there must be none.
    foreach (range(1, 8) as $n) {
        $payment = longRunRenew($world);

        if ($payment === null) {
            break;
        }

        $invoice = longRunAssertRenewal($world, $payment, '17.85');
        $charged[] = substr($payment->createdAt, 0, 10);

        // The plan line is on the invoice, and the waiver takes exactly it off.
        expect(longRunWaiverLine($invoice)['amount_net'] ?? null)->toBe(-2900)
            ->and(longRunWaiverLine($invoice)['applies_to'] ?? null)->not->toBeNull();
    }

    // Every charge was created while the gift ran, and Mollie was told to stop.
    expect($charged)->not->toBeEmpty()
        ->and(max($charged))->toBeLessThan('2027-04-10')
        ->and($world->cancelled)->toBeTrue()
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled)
        // The tenant keeps what the last payment bought, and not a day of unpaid plan.
        ->and($world->billable->getBillingSubscriptionEndsAt()->toDateString())->toBe($world->lastInvoice->period_end->toDateString());

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('turns a gifted subscription into a paying one when the gift was given to continue', function (): void {
    $world = longRunStartGifted('2027-01-10 10:00:00', 90, 'continue_paid', '2027-01-20 10:00:00', ['sso'], 2);

    foreach (range(1, 12) as $n) {
        $payment = longRunRenew($world);

        expect($payment)->not->toBeNull();

        // A charge created before the gift's end is plan-free; the first one after it
        // is the first at the full price.
        $waived = substr($payment->createdAt, 0, 10) < '2027-04-10';
        $invoice = longRunAssertRenewal($world, $payment, $waived ? '17.85' : '52.36');

        expect(longRunWaiverLine($invoice) !== null)->toBe($waived, 'renewal '.$n.' ('.$payment->createdAt.')');
    }

    expect($world->cancelled)->toBeFalse()
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($world->billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('waives the plan for exactly N periods of a paying subscription, then charges it again', function (int $periods): void {
    // Pro + SSO, paying: 52.36 a month.
    $world = longRunStart('monthly', '2027-11-10 10:00:00', '52.36', ['addon_codes' => ['sso']]);

    $first = longRunRenew($world);
    longRunAssertRenewal($world, $first, '52.36');

    // Mid-period, an operator gives the plan free for N periods.
    Carbon::setTestNow(Carbon::parse('2027-12-20 12:00:00', 'UTC'));
    app(AdminExtensionService::class)->freePlanPeriods($world->billable->fresh(), $periods);

    expect($world->heldValue)->toBe('17.85');

    foreach (range(1, $periods + 6) as $n) {
        $payment = longRunRenew($world);
        $waived = $n <= $periods;

        $invoice = longRunAssertRenewal($world, $payment, $waived ? '17.85' : '52.36');

        expect(longRunWaiverLine($invoice) !== null)->toBe($waived, 'renewal '.$n.' after the waiver was given');
    }

    expect($world->cancelled)->toBeFalse()
        ->and($world->billable->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Active);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
})->with([1, 3]);
