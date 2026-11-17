<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidCouponException;
use GraystackIT\MollieBilling\Exceptions\RefundInProgressException;
use GraystackIT\MollieBilling\Exceptions\SubscriptionChangeInProgressException;
use GraystackIT\MollieBilling\Exceptions\UsageLimitExceededException;
use GraystackIT\MollieBilling\Jobs\SyncSeatsJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\Models\BillingProcessedWebhook;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Models\CouponRedemption;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Tests\Concurrency\ConcurrencyTestCase;
use GraystackIT\MollieBilling\Tests\Concurrency\ConcurrencyWorld;
use Illuminate\Support\Facades\DB;
use Workbench\App\FakeMollie\FakeMollie;

/*
 * Two PHP processes, one database, one cache, one (fake) Mollie — released by a
 * barrier into the same operation on the same subject. The Mollie request inside the
 * critical section is slowed down (ConcurrencyWorld::DELAY_MS), so whichever process wins
 * is still inside when the other one arrives. Every other test in the package runs in one
 * process, where none of these guards can be seen doing anything.
 *
 * Each case also asserts that the two attempts really overlapped in time: the later start
 * lies before the earlier finish. Without that a green run could just mean the two ran one
 * after the other.
 *
 * Where the guarded window has no Mollie call in it — a balance, a counter, a reservation
 * read and then written — the read is slowed instead (`pause`: a sleep after every load of
 * that model, see ConcurrencyWorld::registerPauses()). With the guard the other process
 * waits or is turned away for that long; without it, both have read the same value before
 * either writes.
 *
 * Runs on SQLite by default and on PostgreSQL with BILLING_CONCURRENCY_DB=pgsql. On SQLite
 * every write transaction serialises on the database lock, so a missing row lock
 * (`lockForUpdate()`) cannot show there; the wallet and coupon cases are the ones the
 * Postgres run exists for.
 */

uses(ConcurrencyTestCase::class);

/**
 * @param  array{A: array<string, mixed>, B: array<string, mixed>}  $results
 */
function expectOverlap(array $results): void
{
    $latestStart = max($results['A']['started'], $results['B']['started']);
    $earliestFinish = min($results['A']['finished'], $results['B']['finished']);

    expect($latestStart)->toBeLessThan($earliestFinish, 'The two workers did not overlap — the test proved nothing.')
        ->and($results['A']['environment'])->toBe('testing');

    $redis = ConcurrencyWorld::cacheDriver() === 'redis';

    foreach (['A', 'B'] as $worker) {
        expect($results[$worker]['database'])->toBe(ConcurrencyWorld::driver())
            ->and($results[$worker]['cache_store'])->toBe($redis ? \Illuminate\Cache\RedisStore::class : \Illuminate\Cache\FileStore::class)
            ->and($results[$worker]['wallet_lock'])->toBe($redis ? 'redis' : 'file');
    }
}

/**
 * @param  array{A: array<string, mixed>, B: array<string, mixed>}  $results
 * @return array{0: array<string, mixed>, 1: array<string, mixed>} winner, loser
 */
function splitWinner(array $results): array
{
    $winners = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $losers = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] !== 'ok'));

    expect($winners)->toHaveCount(1, 'Exactly one process may carry out the operation: '.json_encode($results))
        ->and($losers)->toHaveCount(1);

    return [$winners[0], $losers[0]];
}

it('lets one of two simultaneous seat changes through and refuses the other while it runs', function (): void {
    $billable = ConcurrencyWorld::subscriberWithPaidRenewal();

    $results = ConcurrencyWorld::runPair(
        'add-seat',
        ['billable_id' => $billable->getKey()],
        ['CreatePaymentRequest', 'UpdateSubscriptionRequest', 'CreatePaymentRefundRequest'],
    );

    expectOverlap($results);
    [, $loser] = splitWinner($results);

    expect($loser['class'])->toBe(SubscriptionChangeInProgressException::class);

    // One seat added, not two — and one change's worth of Mollie traffic: the prorata
    // payment for the extra seat and the PATCH of the subscription amount, once each.
    $mutating = array_intersect_key(
        ConcurrencyWorld::mollieRequestsFromWorkers(),
        array_flip(['CreatePaymentRequest', 'UpdateSubscriptionRequest', 'CreatePaymentRefundRequest', 'CancelSubscriptionRequest']),
    );
    ksort($mutating);

    expect($billable->refresh()->getBillingSeatCount())->toBe(3)
        ->and($mutating)->toBe(['CreatePaymentRequest' => 1, 'UpdateSubscriptionRequest' => 1], 'Mollie saw more than one change: '.json_encode($mutating));
});

it('lets one of two simultaneous refunds of the same invoice through and refuses the other while it runs', function (): void {
    $billable = ConcurrencyWorld::subscriberWithPaidRenewal();
    $invoice = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', '!=', InvoiceKind::Refund->value)
        ->sole();

    $results = ConcurrencyWorld::runPair(
        'refund',
        ['invoice_id' => $invoice->getKey(), 'amount_net' => 500],
        ['CreatePaymentRefundRequest'],
    );

    expectOverlap($results);
    [, $loser] = splitWinner($results);

    expect($loser['class'])->toBe(RefundInProgressException::class)
        ->and(BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund->value)->count())->toBe(1)
        ->and(DB::table('fake_mollie_refunds')->count())->toBe(1)
        ->and(ConcurrencyWorld::mollieRequestsFromWorkers()['CreatePaymentRefundRequest'] ?? 0)->toBe(1)
        ->and($invoice->fresh()->effectiveRefundedNet())->toBe(500);
});

it('refunds one line of an invoice once when two operators refund what is left of it at the same moment', function (): void {
    // Both ask for the line's whole remainder. The refund lock is taken before the line's
    // budget is read, so the second operator is turned away while the first one's refund
    // is at Mollie — not, later, with a line that turned out to be used up.
    $billable = ConcurrencyWorld::subscriberWithPaidRenewal();
    $invoice = BillingInvoice::query()
        ->where('billable_id', $billable->getKey())
        ->where('invoice_kind', '!=', InvoiceKind::Refund->value)
        ->sole();
    $line = app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->refundableLines($invoice)[0];

    $results = ConcurrencyWorld::runPair('refund-line', [
        'invoice_id' => $invoice->getKey(),
        'line_index' => $line['index'],
        'amount_net' => $line['remaining_net'],
    ], ['CreatePaymentRefundRequest']);

    expectOverlap($results);
    [, $loser] = splitWinner($results);

    $notes = BillingInvoice::query()->where('invoice_kind', InvoiceKind::Refund->value)->get();

    expect($loser['class'])->toBe(RefundInProgressException::class, json_encode($results).' Mollie: '.json_encode(ConcurrencyWorld::mollieRequestsFromWorkers()).' notes: '.$notes->count())
        ->and($notes)->toHaveCount(1)
        ->and((int) $notes[0]->amount_net)->toBe(-$line['remaining_net'])
        ->and($notes[0]->line_items[0]['parent_line_item_index'] ?? null)->toBe($line['index'])
        ->and(DB::table('fake_mollie_refunds')->count())->toBe(1)
        ->and(ConcurrencyWorld::mollieRequestsFromWorkers()['CreatePaymentRefundRequest'] ?? 0)->toBe(1)
        ->and(app(\GraystackIT\MollieBilling\Services\Billing\InvoiceService::class)->refundableLines($invoice->fresh(), true)[0]['remaining_net'])->toBe(0);
});

it('processes one of two simultaneous deliveries of the same payment and answers the other 503', function (): void {
    $billable = ConcurrencyWorld::subscriber();
    $payment = FakeMollie::chargeSubscription((string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id']);

    $results = ConcurrencyWorld::runPair('webhook', ['payment_id' => $payment['id']], ['GetPaymentRequest']);

    expectOverlap($results);

    $statuses = [$results['A']['status'], $results['B']['status']];
    sort($statuses);

    // The loser never even fetched the payment: it was turned away at the reservation.
    expect($statuses)->toBe([200, 503], 'Both deliveries were let in: '.json_encode($results))
        ->and(ConcurrencyWorld::mollieRequestsFromWorkers()['GetPaymentRequest'] ?? 0)->toBe(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->count())->toBe(1)
        ->and(BillingProcessedWebhook::query()->where('mollie_payment_id', $payment['id'])->whereNotNull('processed_at')->count())->toBe(1);
});

it('lets exactly one of two simultaneous deliveries take over an abandoned reservation', function (): void {
    // A delivery died holding the reservation (no unwinding, see reserve()); Mollie calls
    // again after the takeover window — twice, at the same moment. Both read the stale
    // row and both call it abandoned. The takeover is conditional on the `received_at`
    // they read, so only one of the two updates matches; the other is a delivery that
    // lost the race, answered 503 like any delivery that finds one in flight.
    $billable = ConcurrencyWorld::subscriber();
    $payment = FakeMollie::chargeSubscription((string) $billable->getBillingSubscriptionMeta()['mollie_subscription_id']);
    ConcurrencyWorld::abandonedReservation($payment['id']);

    $results = ConcurrencyWorld::runPair('webhook', [
        'payment_id' => $payment['id'],
        'pause' => [['model' => BillingProcessedWebhook::class, 'ms' => 1000]],
    ], ['GetPaymentRequest']);

    expectOverlap($results);

    $statuses = [$results['A']['status'], $results['B']['status']];
    sort($statuses);

    expect($statuses)->toBe([200, 503], 'Both deliveries took the reservation over: '.json_encode($results))
        ->and(ConcurrencyWorld::mollieRequestsFromWorkers()['GetPaymentRequest'] ?? 0)->toBe(1)
        ->and(BillingInvoice::query()->where('mollie_payment_id', $payment['id'])->count())->toBe(1)
        ->and(BillingProcessedWebhook::query()->where('mollie_payment_id', $payment['id'])->count())->toBe(1)
        ->and(BillingProcessedWebhook::query()->where('mollie_payment_id', $payment['id'])->whereNotNull('processed_at')->count())->toBe(1);
});

it('activates one of two different first payments of the same customer and answers the other 503', function (): void {
    // Two checkouts, both paid — two payments, so two reservations: reserve() does not
    // keep them apart. The activation claim does: the first gate to commit claims the
    // billable, and the second payment's gate finds the claim while the first activation
    // is still creating the subscription at Mollie.
    $billable = ConcurrencyWorld::customer('first@acme.test');
    [$first, $second] = ConcurrencyWorld::twoPaidFirstPayments($billable);

    $results = ConcurrencyWorld::runPair('webhook', [], ['CreateSubscriptionRequest'], [
        'A' => ['payment_id' => $first],
        'B' => ['payment_id' => $second],
    ]);

    expectOverlap($results);

    $statuses = [$results['A']['status'], $results['B']['status']];
    sort($statuses);

    $billable->refresh();

    expect($statuses)->toBe([200, 503], 'Both payments activated: '.json_encode($results))
        ->and(ConcurrencyWorld::mollieRequestsFromWorkers()['CreateSubscriptionRequest'] ?? 0)->toBe(1)
        ->and(DB::table('fake_mollie_subscriptions')->count())->toBe(1)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->toBe(DB::table('fake_mollie_subscriptions')->value('id'))
        // The 503 delivery stopped at the gate: no invoice for its payment yet — Mollie
        // re-delivers it, and it is then a duplicate payment for the duplicate path.
        ->and(BillingInvoice::query()->whereIn('mollie_payment_id', [$first, $second])->count())->toBe(1)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey('activation_claim');
});

it('runs two simultaneous seat deltas one after the other, and the second waits for the first charge instead of failing', function (): void {
    // Two "member invited" jobs at once. The seat lock is a blocking one: the second job
    // waits while the first one is at Mollie and then resolves its delta from the row the
    // first one wrote. What it finds there is the first change's prorata payment, not yet
    // confirmed by its webhook — and only one unconfirmed change is allowed at a time. So
    // it puts a copy of itself back (`waits = 1`, PENDING_WAIT_SECONDS later) instead of
    // failing, and that copy adds the seat once the first payment is confirmed: 2 → 3 → 4,
    // two charges, nothing lost, nothing added on a stale read.
    ConcurrencyWorld::createJobsTable();
    $billable = ConcurrencyWorld::subscriberWithPaidRenewal();

    $results = ConcurrencyWorld::runPair(
        'seat-job',
        ['billable_id' => $billable->getKey(), 'delta' => 1, 'queue' => 'database'],
        ['CreatePaymentRequest', 'UpdateSubscriptionRequest'],
    );

    expectOverlap($results);

    expect($results['A']['outcome'])->toBe('ok', json_encode($results))
        ->and($results['B']['outcome'])->toBe('ok', json_encode($results))
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3);

    // At Mollie: one change only, so far.
    $counts = ConcurrencyWorld::mollieRequestsFromWorkers();
    ksort($counts);

    expect(array_intersect_key($counts, array_flip(['CreatePaymentRequest', 'UpdateSubscriptionRequest'])))
        ->toBe(['CreatePaymentRequest' => 1, 'UpdateSubscriptionRequest' => 1]);

    // The second change waits on the queue.
    $queued = DB::table('jobs')->where('payload', 'like', '%SyncSeatsJob%')->get();

    expect($queued)->toHaveCount(1);

    $command = unserialize(json_decode((string) $queued[0]->payload, true)['data']['command']);

    expect($command)->toBeInstanceOf(SyncSeatsJob::class)
        ->and($command->delta)->toBe(1)
        ->and($command->waits)->toBe(1)
        ->and($command->requeues)->toBe(0)
        ->and((int) $queued[0]->available_at)->toBeGreaterThanOrEqual(time() + SyncSeatsJob::PENDING_WAIT_SECONDS - 15);

    // The first charge is confirmed; then the copy runs and adds the waiting seat.
    $prorata = DB::table('fake_mollie_payments')->where('metadata', 'like', '%prorata%')->sole();
    expect(FakeMollie::deliverWebhook((string) $prorata->id))->toBe(200);

    $command->handle(app(SyncSeats::class));

    expect($billable->refresh()->getBillingSeatCount())->toBe(4)
        ->and(DB::table('fake_mollie_payments')->where('metadata', 'like', '%prorata%')->count())->toBe(2);
});

it('puts a seat delta that cannot get the lock back on the queue, and it is applied from there', function (): void {
    // Both jobs wait only one second for the lock (the seam SyncSeatsJob has for this);
    // the first holds it for two slowed Mollie calls. The second gives up — nothing has
    // happened yet — and queues a copy of itself a minute later, with the same delta.
    ConcurrencyWorld::createJobsTable();
    $billable = ConcurrencyWorld::subscriberWithPaidRenewal();

    $results = ConcurrencyWorld::runPair(
        'seat-job',
        ['billable_id' => $billable->getKey(), 'delta' => 1, 'wait_seconds' => 1, 'queue' => 'database'],
        ['CreatePaymentRequest', 'UpdateSubscriptionRequest'],
    );

    expectOverlap($results);

    expect($results['A']['outcome'])->toBe('ok', json_encode($results))
        ->and($results['B']['outcome'])->toBe('ok', json_encode($results))
        ->and($billable->refresh()->getBillingSeatCount())->toBe(3);

    $queued = DB::table('jobs')->where('payload', 'like', '%SyncSeatsJob%')->get();

    expect($queued)->toHaveCount(1);

    $command = unserialize(json_decode((string) $queued[0]->payload, true)['data']['command']);

    expect($command)->toBeInstanceOf(SyncSeatsJob::class)
        ->and($command->delta)->toBe(1)
        ->and($command->requeues)->toBe(1)
        ->and((int) $queued[0]->available_at)->toBeGreaterThanOrEqual(time() + SyncSeatsJob::REQUEUE_DELAY_SECONDS - 15);

    // The first change's prorata charge is confirmed (its webhook) — until then the copy
    // would only wait again, see the case above — and then it runs and adds the seat that
    // was waiting.
    $prorata = DB::table('fake_mollie_payments')->where('metadata', 'like', '%prorata%')->sole();
    expect(FakeMollie::deliverWebhook((string) $prorata->id))->toBe(200);

    $command->handle(app(SyncSeats::class));

    expect($billable->refresh()->getBillingSeatCount())->toBe(4);
});

it('refuses one of two simultaneous usages that together pass a hard-capped quota', function (): void {
    // SMS: 50 included, no overage price — usage past the quota is refused, not billed.
    // 30 + 30 > 50: the second debit must see the first one's balance. What keeps the two
    // apart is debit()'s transaction with the billable and wallet rows locked; bavix's own
    // lock only covers its withdraw, after the check.
    $billable = ConcurrencyWorld::subscriber();

    $results = ConcurrencyWorld::runPair('usage', [
        'billable_id' => $billable->getKey(),
        'type' => 'SMS',
        'quantity' => 30,
        'pause' => [['model' => config('wallet.wallet.model'), 'ms' => 300]],
    ], []);

    expectOverlap($results);
    [$winner, $loser] = splitWinner($results);

    expect($loser['class'])->toBe(UsageLimitExceededException::class)
        ->and($winner['result'])->toBe(20)
        ->and((int) $billable->refresh()->getWallet('SMS')->refresh()->balanceInt)->toBe(20);
});

it('redeems the last free slot of a code once when two customers redeem it at the same moment', function (): void {
    // max_redemptions 2, one used. Two customers redeem at once; redeem() reads the
    // counter under `lockForUpdate()`, so the second one reads it after the first one's
    // increment and is told the code is used up.
    $coupon = ConcurrencyWorld::couponWithLastSlot();
    $first = ConcurrencyWorld::customer('one@acme.test');
    $second = ConcurrencyWorld::customer('two@acme.test');

    $results = ConcurrencyWorld::runPair('redeem', [
        'coupon_id' => $coupon->getKey(),
        'pause' => [['model' => Coupon::class, 'ms' => 1000]],
    ], [], [
        'A' => ['billable_id' => $first->getKey()],
        'B' => ['billable_id' => $second->getKey()],
    ]);

    expectOverlap($results);
    [, $loser] = splitWinner($results);

    expect($loser['class'])->toBe(InvalidCouponException::class)
        ->and((int) $coupon->fresh()->redemptions_count)->toBe(2)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->getKey())->count())->toBe(1);
});
