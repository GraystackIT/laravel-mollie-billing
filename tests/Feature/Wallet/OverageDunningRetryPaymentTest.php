<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingOverageChargeJob;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\OverageBillingFailedNotification;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Tests\Support\UsageLongRunHarness;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * The payments of one overage dunning cycle after the first one.
 *
 * A retry after a *failed payment* is a new payment, not a repeat of the old request — and
 * both the idempotency key and the snapshot treated it as the latter:
 *
 *  - the key was the cycle's (`created_at` + gross), so the retry of a card payment that
 *    failed inside Mollie's one-hour window was answered with the failed payment itself.
 *    No second or third attempt was ever made, and the cycle sat `pending` until the stale
 *    sweep wrote it off three days later, without telling the customer;
 *  - the snapshot kept the failed payment's id while the next one was being created, so a
 *    create that threw was neither marked as "answer lost" nor retried by the nightly pass,
 *    and a payment whose answer was lost was never found again — the sweep wrote the cycle
 *    off on the old id, and the lost payment then settled on top of the credited-back units;
 *  - and the `canceled` webhook for a payment the stale sweep had just cancelled started a
 *    fresh cycle at once, outside any renewal and without the three days of rest.
 *
 * The harness answers idempotency keys the way the sandbox measured Mollie does.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();
});

/** January ran 37 Tokens over; the sweep the night before the 10 February renewal charges it. */
function overageDunningRetryRun(): UsageLongRunHarness
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

    expect($run->paymentRequests)->toBe(1);

    return $run;
}

/** @return array<string, mixed> */
function overageDunningSnapshot(UsageLongRunHarness $run): array
{
    return (array) ($run->fresh()->getBillingSubscriptionMeta()[ChargeUsageOverageDirectly::META_KEY] ?? []);
}

it('makes a new payment for each dunning retry when a card fails inside the idempotency hour', function (): void {
    $run = overageDunningRetryRun();
    $seen = [];

    foreach ([1, 2, 3] as $attempt) {
        $id = $run->openOveragePayment();

        expect($id)->not->toBeNull("attempt {$attempt} has a payment")
            ->and($seen)->not->toContain($id);

        $seen[] = $id;

        // A card refuses within minutes — well inside the hour Mollie keeps a key.
        $run->travelTo(Carbon::now('UTC')->addMinutes(10));
        $run->resolveOverage($id, 'failed');
    }

    // Three distinct payments against the mandate, not one replayed three times.
    expect($run->paymentRequests)->toBe(3)
        ->and($run->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);

    // And the customer is told the cycle is over — the stale sweep's write-off never was.
    Notification::assertSentToTimes($run->fresh(), OverageBillingFailedNotification::class, 1);
});

it('marks a retry whose payment could not be created, and the nightly pass tries again', function (): void {
    $run = overageDunningRetryRun();
    $first = $run->openOveragePayment();

    // A SEPA debit: p1 fails three days later, and the retry's create-payment request does
    // not get through (a Mollie 5xx).
    $run->failPaymentCreationWith = new RuntimeException('[503] Service Unavailable');
    $run->travelTo('2026-02-12T01:10:00Z');
    $run->resolveOverage($first, 'failed');

    $snapshot = overageDunningSnapshot($run);

    // Not the failed payment's id — that one is over. "We asked and do not know" instead.
    expect($snapshot['payment_id'] ?? null)->toBeNull()
        ->and($snapshot)->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and(app(ChargeUsageOverageDirectly::class)->paymentMayExist($run->fresh()))->toBeTrue();

    // Mollie is back. The stale sweep asks Mollie's payment list: the only overage payment
    // there is the cycle's earlier, failed one — which is not the one that was lost.
    $run->failPaymentCreationWith = null;
    $run->travelTo('2026-02-12T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    expect($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($meta[ChargeUsageOverageDirectly::META_KEY])->not->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and($meta[ChargeUsageOverageDirectly::META_KEY]['payment_id'] ?? null)->toBeNull()
        ->and($run->units('Tokens', WalletUsageService::DEBT_DEFERRED_REASON))->toBe(0);

    // The next night's pass 3 makes the second attempt of the cycle.
    $run->travelTo('2026-02-13T01:00:00Z');
    $run->night();

    $second = overageDunningSnapshot($run)['payment_id'] ?? null;

    expect($run->paymentRequests)->toBe(2)
        ->and($second)->not->toBeNull()
        ->and($second)->not->toBe($first);
});

it('finds a retry payment whose answer was lost, and credits its units once when it settles', function (): void {
    $run = overageDunningRetryRun();
    $first = $run->openOveragePayment();

    // A SEPA debit: p1 fails three days later, and the retry creates p2 at Mollie — but the
    // answer never arrives.
    $run->losePaymentCreationAnswerWith = new RuntimeException('cURL error 28: read timeout');
    $run->travelTo('2026-02-12T01:10:00Z');
    $run->resolveOverage($first, 'failed');
    $run->losePaymentCreationAnswerWith = null;

    $lost = array_key_last($run->createdPayments);

    expect($lost)->not->toBe($first)
        ->and(overageDunningSnapshot($run))->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY);

    // The stale sweep that night: past its patience for the cycle (three days since p1) and
    // for the lost answer (an hour).
    $run->travelTo('2026-02-12T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    // The lost payment is adopted rather than the cycle written off on the old, failed one.
    expect($meta[ChargeUsageOverageDirectly::META_KEY]['payment_id'] ?? null)->toBe($lost)
        ->and($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_PENDING)
        ->and($run->units('Tokens', WalletUsageService::DEBT_DEFERRED_REASON))->toBe(0);

    // It settles. 100 credited − 137 used + 37 paid for = 0, not 37 units for free.
    $run->resolveOverage($lost, 'paid');

    expect($run->balance('Tokens'))->toBe(0)
        ->and($run->units('Tokens', 'overage_settlement'))->toBe(37)
        ->and($run->invoiceCount('overage'))->toBe(1)
        ->and($run->paymentRequests)->toBe(2);
});

it('waits out the lost answer of a retry on its own clock, not the cycle\'s', function (): void {
    $run = overageDunningRetryRun();
    $first = $run->openOveragePayment();

    $run->losePaymentCreationAnswerWith = new RuntimeException('cURL error 28: read timeout');
    $run->travelTo('2026-02-12T04:00:00Z');
    $run->resolveOverage($first, 'failed');
    $run->losePaymentCreationAnswerWith = null;

    // A quarter of an hour later. The cycle is three days old, but the question "did that
    // request arrive?" is not: the retry ladder and Mollie's key window may still answer it,
    // and a payment adopted now is one a queued re-entry of the retry counts as failed.
    $run->travelTo('2026-02-12T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect(overageDunningSnapshot($run))->toHaveKey(ChargeUsageOverageDirectly::PAYMENT_UNKNOWN_KEY)
        ->and(overageDunningSnapshot($run)['payment_id'] ?? null)->toBeNull();

    // The next night's sweep, well past the hour, settles it.
    $run->travelTo('2026-02-13T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect(overageDunningSnapshot($run)['payment_id'] ?? null)->toBe(array_key_last($run->createdPayments));
});

it('does not start a new cycle when the stale sweep cancels a payment and Mollie reports it canceled', function (): void {
    $run = overageDunningRetryRun();
    $first = $run->openOveragePayment();

    // p1 stays open at Mollie for over a fortnight: the stale sweep cancels it and defers the
    // cycle, so the debt stands for the next renewals.
    $run->travelTo('2026-02-24T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($run->fresh()->getBillingSubscriptionMeta()['usage_overage_status'] ?? null)
        ->toBe(ChargeUsageOverageDirectly::STATUS_FAILED);

    // Mollie's webhook for the cancellation follows.
    $run->travelTo(Carbon::now('UTC')->addMinutes(1));
    $run->resolveOverage($first, 'canceled');

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    // The debt rests and waits for a renewal; the cancellation is not a fourth attempt, and
    // it does not use up one of the renewals' recollections.
    expect($run->paymentRequests)->toBe(1)
        ->and($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
        ->and($meta[ChargeUsageOverageDirectly::RECOLLECTIONS_KEY] ?? 0)->toBe(0);
});

it('ends a cycle whose retry Mollie refuses for good instead of retrying it every night', function (): void {
    $run = overageDunningRetryRun();
    $first = $run->openOveragePayment();
    $cycleStartedAt = (string) overageDunningSnapshot($run)['created_at'];

    // A SEPA debit fails with MD01 three days later, and Mollie invalidates the mandate:
    // from now on every create-payment request is refused.
    $run->failPaymentCreationWith = \GraystackIT\MollieBilling\Tests\Support\MollieApiExceptionFactory::withStatus(
        422,
        'Error executing API call (422: Unprocessable Entity): The mandate is invalid.',
    );
    $run->travelTo('2026-02-12T01:10:00Z');
    $run->resolveOverage($first, 'failed');

    $createsAt = static fn (): int => count(array_filter(
        $run->requests,
        static fn (string $class): bool => $class === \Mollie\Api\Http\Requests\CreatePaymentRequest::class,
    ));

    // Three weeks of nights: the nightly pass at 01:00, the stale sweep at 04:15.
    for ($day = Carbon::parse('2026-02-12'); $day->lessThanOrEqualTo(Carbon::parse('2026-03-05')); $day->addDay()) {
        $run->travelTo($day->copy()->setTime(1, 0));
        $run->night();
        $run->travelTo($day->copy()->setTime(4, 15));
        app(CleanupStalePendingOverageChargeJob::class)->handle();
    }

    $meta = $run->fresh()->getBillingSubscriptionMeta();

    // No payment exists for the cycle's last attempt — Mollie said so — and none can be made.
    // The hard limit ends the cycle: the debt stands for the renewals, the subscription is
    // past due for it, and nothing asks Mollie again.
    expect($meta['usage_overage_status'] ?? null)->toBe(ChargeUsageOverageDirectly::STATUS_FAILED)
        ->and($run->fresh()->getBillingSubscriptionStatus())->toBe(\GraystackIT\MollieBilling\Enums\SubscriptionStatus::PastDue)
        ->and($run->units('Tokens', WalletUsageService::DEBT_DEFERRED_REASON))->toBe(37)
        ->and((string) ($meta[ChargeUsageOverageDirectly::META_KEY]['created_at'] ?? ''))->toBe($cycleStartedAt);

    $createsAfterWriteOff = $createsAt();

    $run->travelTo('2026-03-06T01:00:00Z');
    $run->night();
    $run->travelTo('2026-03-06T04:15:00Z');
    app(CleanupStalePendingOverageChargeJob::class)->handle();

    expect($createsAt())->toBe($createsAfterWriteOff)
        ->and($run->paymentRequests)->toBe(1);
});
