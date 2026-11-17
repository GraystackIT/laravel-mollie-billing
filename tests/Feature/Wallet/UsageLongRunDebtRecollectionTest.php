<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminOverageBillingFailedNotification;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * An overage nobody could collect is not written off after the first dunning cycle.
 *
 * It used to be: three failed payments, an admin mail, and the debt was gone with the
 * next renewal's reset. Now the debt stands — off the wallet, so the customer keeps the
 * quota they pay for — and each of the next renewals tries to collect it again, on its
 * own. Only when `MAX_RENEWAL_RECOLLECTIONS` of those have failed too is it written off,
 * and only then does an operator hear about it. While it stands the subscription is past
 * due (see OverageDebtPastDueTest); it keeps renewing, and it is never auto-cancelled
 * for the debt alone.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

/**
 * January ran 37 Tokens over; the sweep's charge on 9 February fails three times.
 */
function usageLongRunFailedFirstCycle(): UsageLongRunHarness
{
    UsageLongRunHarness::definePlan();
    $run = UsageLongRunHarness::boot();

    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
    MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);

    $start = Carbon::parse('2026-01-10T09:00:00Z');
    $run->travelTo($start);
    $run->createBillable(['subscription_period_starts_at' => $start]);
    $run->credit('Tokens', 100);
    $run->credit('SMS', 50);

    $run->travelTo('2026-01-24T12:00:00Z');
    $run->use('Tokens', 137);

    for ($day = Carbon::parse('2026-01-25'); $day->lessThanOrEqualTo(Carbon::parse('2026-02-09')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
    }

    usageLongRunFailEveryOpenPayment($run);

    return $run;
}

function usageLongRunFailEveryOpenPayment(UsageLongRunHarness $run): void
{
    for ($attempt = 0; $attempt < 6 && ($id = $run->openOveragePayment()) !== null; $attempt++) {
        $run->travelTo(Carbon::now('UTC')->addMinutes(10));
        $run->resolveOverage($id, 'failed');
    }
}

it('keeps a failed overage debt standing and collects it at a later renewal, without an operator', function (): void {
    $run = usageLongRunFailedFirstCycle();
    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();

    // Three payments failed. The debt is recorded, the wallet is clean again, and nobody
    // in operations has been bothered.
    expect($run->paymentRequests)->toBe(3)
        ->and($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
        ->and((int) ($meta[ChargeUsageOverageDirectly::META_KEY]['amount_net'] ?? 0))->toBe(370)
        ->and($run->balance('Tokens'))->toBe(0);

    Notification::assertSentToTimes($billable, OverageBillingFailedNotification::class, 1);
    Notification::assertNothingSentTo(new AnonymousNotifiable);

    $requestsAfter = [];

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10'] as $renewal) {
        // The nights in between: nothing is charged on them.
        for ($day = Carbon::parse($renewal)->subDays(5); $day->lessThan(Carbon::parse($renewal)); $day->addDay()) {
            $run->travelTo($day->copy()->setTime(1, 0));
            $run->night();
        }

        $run->travelTo($renewal.'T09:02:00Z');
        $run->renew($renewal.'T09:00:00Z');

        // The card works again in May.
        if ($renewal === '2026-05-10') {
            $run->settleOpenOverage();
        } else {
            usageLongRunFailEveryOpenPayment($run);
        }

        $requestsAfter[$renewal] = $run->paymentRequests;

        // Whatever happens to the debt, the renewal that was paid for hands out the full
        // quota of the period. The subscription is past due — nothing new can be booked —
        // for exactly as long as the debt is open.
        expect($run->fresh()->subscription_status)
            ->toBe($renewal < '2026-05-10' ? SubscriptionStatus::PastDue : SubscriptionStatus::Active, $renewal)
            ->and($run->balance('Tokens'))->toBe(100, $renewal);
    }

    expect($requestsAfter)->toBe([
        // A day after the cycle gave up: not again yet.
        '2026-02-10' => 3,
        // One fresh cycle per renewal, three payments each …
        '2026-03-10' => 6,
        '2026-04-10' => 9,
        // … until one is paid, at the first attempt.
        '2026-05-10' => 10,
        // And then it is over.
        '2026-06-10' => 10,
    ]);

    // Collected in full, once: 37 units at 10 ct.
    expect($run->overageInvoicedNet())->toBe(370)
        ->and($run->invoiceCount('overage'))->toBe(1)
        // The units were handed back when the debt left the wallet — not a second time.
        ->and($run->units('Tokens', 'overage_settlement'))->toBe(0)
        ->and($run->count('Tokens', 'period_credit'))->toBe(5);

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('usage_overage')
        ->and($meta)->not->toHaveKey('usage_overage_status');

    // The customer was told each time a cycle failed; operations never.
    Notification::assertSentToTimes($run->fresh(), OverageBillingFailedNotification::class, 3);
    Notification::assertNothingSentTo(new AnonymousNotifiable);
});

it('writes the debt off and tells an operator only after the renewals have had their attempts', function (): void {
    $run = usageLongRunFailedFirstCycle();

    $requestsAfter = [];

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10'] as $renewal) {
        for ($day = Carbon::parse($renewal)->subDays(5); $day->lessThan(Carbon::parse($renewal)); $day->addDay()) {
            $run->travelTo($day->copy()->setTime(1, 0));
            $run->night();
        }

        $run->travelTo($renewal.'T09:02:00Z');
        $run->renew($renewal.'T09:00:00Z');
        usageLongRunFailEveryOpenPayment($run);

        $requestsAfter[$renewal] = $run->paymentRequests;

        if ($renewal === '2026-04-10') {
            // Two renewals have tried and failed: still nothing for an operator to do.
            Notification::assertNothingSentTo(new AnonymousNotifiable);
        }
    }

    $attempts = ChargeUsageOverageDirectly::MAX_RENEWAL_RECOLLECTIONS;

    expect($attempts)->toBe(3);
    expect($requestsAfter)->toBe([
        '2026-02-10' => 3,
        '2026-03-10' => 6,
        '2026-04-10' => 9,
        '2026-05-10' => 12,
        // Written off in May: June and July do not try again.
        '2026-06-10' => 12,
        '2026-07-10' => 12,
    ]);

    $billable = $run->fresh();
    $meta = $billable->getBillingSubscriptionMeta();

    expect($meta)->not->toHaveKey('usage_overage')
        ->and($meta)->not->toHaveKey('usage_overage_status')
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($run->balance('Tokens'))->toBe(100)
        ->and($run->overageInvoicedNet())->toBe(0);

    // One message to operations, at the very end.
    Notification::assertSentTimes(AdminOverageBillingFailedNotification::class, 1);
    Notification::assertNotSentTo(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class);
    Notification::assertSentToTimes($billable, OverageBillingFailedNotification::class, 4);

    // And a line in the audit trail saying what was given up, and how much.
    $entry = BillingAuditEntry::model()::query()->where('event', 'overage_written_off')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->properties['replace']['amount_net_cents'])->toBe(370)
        ->and((int) $entry->properties['replace']['attempts'])->toBe(3);
});
