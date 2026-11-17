<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;

require_once __DIR__.'/Support.php';

/*
 * The status transition matrix: from every subscription status, what each thing that can
 * happen to a subscription does to it.
 *
 * Rows are the six statuses, columns the triggers. A cell names the status afterwards;
 * `refused` means the trigger threw and the status did not move. The table below is the
 * specification — a cell that changes is a behaviour change somebody has to mean.
 *
 * | from \ trigger | renewal paid | renewal failed | cancel at period end | cancel now | resubscribe | night after the end |
 */

beforeEach(function (): void {
    longRunBoot();
    lifecycleCatalogExtras();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A Mollie subscriber put into `$from`, with the fields that state really carries.
 * Signed up 15 January, so the paid period ends 15 February.
 */
function transitionMatrixWorld(SubscriptionStatus $from): LongRunWorld
{
    $world = longRunStart('monthly', '2027-01-15 10:00:00', '34.51');
    $billable = $world->billable;

    match ($from) {
        SubscriptionStatus::Active => null,
        SubscriptionStatus::Trial => $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Carbon::parse('2027-02-15 10:00:00', 'UTC'),
        ])->save(),
        SubscriptionStatus::PastDue => $billable->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'subscription_meta' => $billable->getBillingSubscriptionMeta() + [
                'past_due_since' => '2027-02-10T00:00:00+00:00',
                'payment_failure' => ['payment_id' => 'tr_earlier', 'reason' => 'insufficient_funds'],
            ],
        ])->save(),
        SubscriptionStatus::Cancelled => $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Cancelled,
            'subscription_ends_at' => Carbon::parse('2027-02-15 10:00:00', 'UTC'),
        ])->save(),
        SubscriptionStatus::Expired => $billable->forceFill([
            'subscription_status' => SubscriptionStatus::Expired,
            'subscription_source' => SubscriptionSource::None,
            'subscription_plan_code' => null,
            'subscription_interval' => null,
            'subscription_ends_at' => Carbon::parse('2027-01-20 10:00:00', 'UTC'),
        ])->save(),
        SubscriptionStatus::New => $billable->forceFill([
            'subscription_status' => SubscriptionStatus::New,
            'subscription_source' => SubscriptionSource::None,
            'subscription_plan_code' => null,
            'subscription_interval' => null,
        ])->save(),
    };

    $world->billable = $billable->fresh();

    return $world;
}

/** Apply a trigger; returns the status afterwards, or `refused`. */
function transitionMatrixApply(LongRunWorld $world, string $trigger): string
{
    $before = $world->billable->fresh()->getBillingSubscriptionStatus();

    try {
        match ($trigger) {
            'renewal paid' => longRunRenew($world),
            'renewal failed' => longRunDeliver($world, longRunCreateCharge($world, status: 'failed'), '2027-02-16 07:00:00'),
            'cancel at period end' => (function () use ($world): void {
                Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
                $world->billable->fresh()->cancelBillingSubscription(false);
            })(),
            'cancel now' => (function () use ($world): void {
                Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'UTC'));
                $world->billable->fresh()->cancelBillingSubscription(true);
            })(),
            'resubscribe' => (function () use ($world): void {
                // The subscription a resubscribe follows was cancelled at Mollie when it
                // ended — and Mollie keeps descriptions unique among a customer's active
                // subscriptions, so its successor could not be created otherwise.
                if (in_array($world->billable->fresh()->getBillingSubscriptionStatus(), [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true)) {
                    $world->cancelled = true;
                }
                Carbon::setTestNow(Carbon::parse('2027-01-25 09:00:00', 'UTC'));
                $world->billable->fresh()->resubscribeBillingPlan();
            })(),
            'night after the end' => (function (): void {
                Carbon::setTestNow(Carbon::parse('2027-02-16 02:00:00', 'UTC'));
                app()->call([new PrepareUsageOverageJob, 'handle']);
            })(),
        };
    } catch (\Throwable $e) {
        expect($world->billable->fresh()->getBillingSubscriptionStatus())->toBe($before, 'a refused trigger moved the status: '.$e->getMessage());

        return 'refused';
    }

    return $world->billable->fresh()->getBillingSubscriptionStatus()->value;
}

it('moves a subscription only along the allowed transitions', function (string $from, string $trigger, string $expected): void {
    $world = transitionMatrixWorld(SubscriptionStatus::from($from));

    expect(transitionMatrixApply($world, $trigger))->toBe($expected, "{$from} --[{$trigger}]--> ?");

    // Whatever happened, access follows the status — never the other way round.
    $billable = $world->billable->fresh();
    $status = $billable->getBillingSubscriptionStatus();

    if (in_array($status, [SubscriptionStatus::New, SubscriptionStatus::Expired], true)) {
        expect($billable->hasAccessibleBillingSubscription())->toBeFalse();
    }

    if ($status === SubscriptionStatus::Active && $billable->getBillingSubscriptionEndsAt() === null) {
        expect($billable->hasAccessibleBillingSubscription())->toBeTrue();
    }
})->with(function (): array {
    $table = [
        //             renewal paid  renewal failed  cancel@end    cancel now   resubscribe  night after end
        'active' => ['active', 'past_due', 'cancelled', 'cancelled', 'refused', 'active'],
        'trial' => ['active', 'past_due', 'cancelled', 'cancelled', 'refused', 'trial'],
        'past_due' => ['active', 'past_due', 'cancelled', 'cancelled', 'refused', 'past_due'],
        'cancelled' => ['cancelled', 'cancelled', 'cancelled', 'cancelled', 'active', 'expired'],
        'expired' => ['expired', 'expired', 'refused', 'refused', 'refused', 'expired'],
        'new' => ['new', 'new', 'refused', 'refused', 'refused', 'new'],
    ];
    $triggers = ['renewal paid', 'renewal failed', 'cancel at period end', 'cancel now', 'resubscribe', 'night after the end'];

    $cases = [];
    foreach ($table as $from => $row) {
        foreach ($triggers as $i => $trigger) {
            $cases["{$from} → {$trigger}"] = [$from, $trigger, $row[$i]];
        }
    }

    return $cases;
});

it('refuses to cancel where there is nothing to cancel, and gives no access for it', function (string $from): void {
    // Was: `new` → `cancelled` with an end date thirty days ahead, which reads as access.
    $world = transitionMatrixWorld(SubscriptionStatus::from($from));
    \Illuminate\Support\Facades\Notification::fake();

    expect(fn () => $world->billable->fresh()->cancelBillingSubscription(false))
        ->toThrow(\GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException::class);

    $billable = $world->billable->fresh();

    expect($billable->getBillingSubscriptionStatus()->value)->toBe($from)
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
    \Illuminate\Support\Facades\Notification::assertNothingSent();
})->with(['new', 'expired']);

it('gives up on a subscription that stayed past due for longer than past_due_max_days', function (): void {
    // Past due since 10 February, limit 30 days: still past due on the night of 11 March,
    // cancelled on the night of 13 March.
    $world = transitionMatrixWorld(SubscriptionStatus::PastDue);

    Carbon::setTestNow(Carbon::parse('2027-03-11 02:00:00', 'UTC'));
    app()->call([new PrepareUsageOverageJob, 'handle']);
    expect($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::PastDue);

    Carbon::setTestNow(Carbon::parse('2027-03-13 02:00:00', 'UTC'));
    app()->call([new PrepareUsageOverageJob, 'handle']);
    expect($world->billable->fresh()->getBillingSubscriptionStatus())->toBe(SubscriptionStatus::Cancelled);
});
