<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Jobs\RetrySubscriptionActivationJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Notifications\AdminPlanChangeFailedNotification;
use GraystackIT\MollieBilling\Support\ActivationRetry;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;

require_once __DIR__.'/Support/RenewalLongRun.php';

/*
 * The activation retry on an app without a queue worker, and the net under the job.
 *
 * `QUEUE_CONNECTION=sync` runs a dispatched job at once and ignores its delay. Dispatched
 * from the webhook, the retry therefore ran INSIDE the delivery it is meant to repeat: it
 * found the delivery's own reservation, could not finish, threw — the delivery answered
 * 500 — and because a sync job that throws has "failed", the payment was refunded on the
 * spot, seconds after a hiccup the retry ladder exists to sit out.
 */

beforeEach(function (): void {
    longRunBoot();
    Notification::fake();
    MollieBilling::notifyAdminUsing(fn (): array => [new AnonymousNotifiable]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    MollieBilling::notifyAdminUsing(fn (): array => []);
});

it('does not run the retry inside the delivery when the queue is sync', function (): void {
    config()->set('queue.default', 'sync');

    // The webhook answers 200 (longRunStart asserts it) although Mollie refused the
    // subscription for the rest of this request.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 5);

    expect($world->billable->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_first')
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count())->toBe(0)
        ->and(collect($world->requests)->filter(fn ($r) => $r instanceof CreateSubscriptionRequest)->count())->toBe(1);

    Notification::assertNothingSent();
});

it('picks an activation up again from the sweep when its job was lost', function (): void {
    config()->set('queue.default', 'database');
    Bus::fake([RetrySubscriptionActivationJob::class]);

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 1);

    Bus::assertDispatchedTimes(RetrySubscriptionActivationJob::class, 1);

    // The same hour: the ladder is running, the sweep leaves it alone.
    Carbon::setTestNow(Carbon::parse('2027-01-15 10:30:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);
    Bus::assertDispatchedTimes(RetrySubscriptionActivationJob::class, 1);

    // Past the whole ladder (1 + 5 + 15 + 60 + 360 minutes) the marker is still there:
    // the job is gone — a flushed queue, a sync app — and nothing else would ever look.
    Carbon::setTestNow(Carbon::parse('2027-01-16 02:00:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);

    Bus::assertDispatchedTimes(RetrySubscriptionActivationJob::class, 2);
    Bus::assertDispatched(RetrySubscriptionActivationJob::class, fn (RetrySubscriptionActivationJob $job): bool => $job->paymentId === 'tr_lr_first'
        && (string) $job->billableId === (string) $world->billable->getKey());
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 0);
});

it('on a sync queue lets the hourly sweep run the activation again until it goes through, with no refund', function (): void {
    config()->set('queue.default', 'sync');

    // Mollie refuses the subscription four times: the delivery itself and the first three
    // sweeps. On a real queue the worker's ladder sits that out; on `sync` a throw is final,
    // so the first sweep's attempt used to end in `failed()` — the payment refunded and an
    // operator mailed for a hiccup that was gone two hours later.
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 4);

    $created = fn (): int => collect($world->requests)->filter(fn ($r) => $r instanceof CreateSubscriptionRequest)->count();
    $refunds = fn (): int => collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count();

    foreach (['11:05', '12:05', '13:05'] as $time) {
        Carbon::setTestNow(Carbon::parse("2027-01-15 {$time}:00", 'UTC'));
        app()->call([new SweepPendingRetriesJob, 'handle']);

        expect($world->billable->fresh()->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_first')
            ->and($refunds())->toBe(0);
    }

    expect($created())->toBe(4);
    Notification::assertNothingSent();

    // The fourth sweep: Mollie takes it, the subscription is there, the marker is gone.
    Carbon::setTestNow(Carbon::parse('2027-01-15 14:05:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);

    $billable = $world->billable->fresh();

    expect($created())->toBe(5)
        ->and($refunds())->toBe(0)
        ->and($billable->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY)
        ->and($billable->getBillingSubscriptionMeta()['mollie_subscription_id'] ?? null)->not->toBeNull();
    Notification::assertNothingSent();
});

it('on a sync queue refunds once the ladder is over, and only then', function (): void {
    config()->set('queue.default', 'sync');

    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51', [], [], expectActivated: false,
        beforeDelivery: fn (LongRunWorld $w) => $w->refuse[CreateSubscriptionRequest::class] = 100);

    $refunds = fn (): int => collect($world->requests)->filter(fn ($r) => $r instanceof CreatePaymentRefundRequest)->count();

    // Every hour within the ladder (7 h 22 min): tried, kept, nobody told.
    for ($hour = 11; $hour <= 17; $hour++) {
        Carbon::setTestNow(Carbon::parse(sprintf('2027-01-15 %02d:05:00', $hour), 'UTC'));
        app()->call([new SweepPendingRetriesJob, 'handle']);
    }

    expect($refunds())->toBe(0)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta()[ActivationRetry::META_KEY] ?? [])->toHaveKey('tr_lr_first');
    Notification::assertNothingSent();

    // Past the ladder: the last run fails for good — the payment is refunded, one mail.
    Carbon::setTestNow(Carbon::parse('2027-01-15 18:05:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);
    Carbon::setTestNow(Carbon::parse('2027-01-15 19:05:00', 'UTC'));
    app()->call([new SweepPendingRetriesJob, 'handle']);

    expect($refunds())->toBe(1)
        ->and($world->billable->fresh()->getBillingSubscriptionMeta())->not->toHaveKey(ActivationRetry::META_KEY);
    Notification::assertSentToTimes(new AnonymousNotifiable, AdminPlanChangeFailedNotification::class, 1);
});
