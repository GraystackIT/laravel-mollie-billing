<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

/**
 * The states a subscription reaches when nobody pays and nobody looks.
 *
 * A mandate-less expired trial has to keep walking towards Expired, a scheduled change
 * has to land where the period actually ends, and neither an extension nor a schedule
 * may bring an ended subscription back to life.
 */
beforeEach(function (): void {
    Notification::fake();

    // Midday, fixed — the calendar-day windows in these jobs need a clock that is not
    // sitting on a boundary. See ProcessTrialLifecycleJobTest.
    test()->travelTo(BillingTime::nowUtc()->startOfDay()->addHours(12));

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => ['reports'],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 2000,
                'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
});

function edgeBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Edge '.uniqid(),
        'email' => uniqid('edge-').'@x.test',
        'billing_country' => 'AT',
    ], $overrides));

    return $b->refresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// 11a — a mandate-less expired trial must reach Expired
// ─────────────────────────────────────────────────────────────────────────────

it('stamps past_due_since when a trial expires without a mandate', function (): void {
    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(20),
        'trial_ends_at' => BillingTime::nowUtc()->subHours(3),
    ]);

    app(ProcessTrialLifecycleJob::class)->handle();

    $fresh = $billable->fresh();

    // The status flip alone is what PrepareUsageOverageJob's auto-cancel pass cannot see:
    // it keys off exactly this stamp, so without it the row sat in PastDue forever.
    expect($fresh->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($fresh->getBillingSubscriptionMeta()['past_due_since'] ?? null)->toBeString();
});

it('auto-cancels an expired trial once past_due_max_days has passed', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(50),
        'trial_ends_at' => BillingTime::nowUtc()->subDays(40),
    ]);

    // Day 40 of a lapsed trial: the expiry pass stamps the clock retroactively at "now",
    // so a second sweep 31 days later is what actually cancels. Run the pass, then
    // back-date the stamp to what it would have been and let the overage job see it.
    app(ProcessTrialLifecycleJob::class)->handle();

    $billable->refresh();
    $meta = $billable->getBillingSubscriptionMeta();
    $meta['past_due_since'] = BillingTime::nowUtc()->subDays(40)->toIso8601String();
    $billable->forceFill(['subscription_meta' => $meta])->save();

    app(PrepareUsageOverageJob::class)->handle(
        app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class),
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class),
    );

    // PastDue → Cancelled. The next run's pass 1 then wipes the plan fields, which is
    // what finally closes `hasPlanFeature()` for a customer who never paid.
    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});

it('does not hand a past-due local subscription a fresh month of quota', function (): void {
    $periodStart = BillingTime::nowUtc()->subMonth()->addDay();

    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        // Anniversary is tomorrow: `nextBillingDate()` keeps cycling for a past-due row
        // because the period anchor is never cleared.
        'subscription_period_starts_at' => $periodStart,
        'subscription_meta' => ['past_due_since' => BillingTime::nowUtc()->subDays(5)->toIso8601String()],
    ]);

    // 40 of the plan's 100 tokens left, i.e. mid-period consumption.
    $billable->creditBillingUsage('Tokens', 40);

    app(PrepareUsageOverageJob::class)->handle(
        app(\GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly::class),
        app(\GraystackIT\MollieBilling\Services\Wallet\WalletUsageService::class),
    );

    // A past-due local subscription is not renewing, it is waiting to be cancelled.
    // The quota reset used to fire on every anniversary, indefinitely — the balance would
    // be back at the plan's full 100 and the period anchor moved on.
    expect((int) $billable->fresh()->getWallet('Tokens')->balanceInt)->toBe(40)
        ->and($billable->fresh()->subscription_period_starts_at->toDateString())
        ->toBe($periodStart->toDateString());
});

// ─────────────────────────────────────────────────────────────────────────────
// 11b — scheduling and extending must respect the state they find
// ─────────────────────────────────────────────────────────────────────────────

it('anchors a trial change on the trial end, not on the period anniversary', function (): void {
    $trialEnd = BillingTime::nowUtc()->addDays(14);

    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'mollie_mandate_id' => 'mdt_edge',
        'mollie_customer_id' => 'cst_edge',
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'trial_ends_at' => $trialEnd,
    ]);

    app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'seats' => 2,
    ]);

    $fresh = $billable->fresh();

    // Day 14, where Mollie's first charge actually lands — not day 30. Anchoring on the
    // anniversary applied the change mid-first-paid-period as an `internal` immediate
    // update, which waives the lapsed-period guard and raised the very prorata charge the
    // customer scheduled the change to avoid.
    expect($fresh->scheduled_change_at->toDateString())->toBe($trialEnd->toDateString())
        ->and($fresh->getBillingSubscriptionMeta()['scheduled_change']['scheduled_at'])
        ->toStartWith($trialEnd->toDateString());
});

it('refuses to schedule a change for a cancelled subscription', function (): void {
    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(25),
    ]);

    // apply() drops a change it finds in a non-applicable state, so accepting one here
    // promised a "scheduled for <date>" the dispatcher would silently discard.
    expect(fn () => app(ScheduleSubscriptionChange::class)->schedule($billable, [
        'plan_code' => 'pro',
        'seats' => 3,
    ]))->toThrow(InvalidSubscriptionStateException::class);

    expect($billable->fresh()->scheduled_change_at)->toBeNull()
        ->and($billable->fresh()->getBillingSubscriptionMeta()['scheduled_change'] ?? null)->toBeNull();
});

it('refuses to extend the trial of a cancelled or expired subscription', function (): void {
    foreach ([SubscriptionStatus::Cancelled, SubscriptionStatus::Expired] as $status) {
        $billable = edgeBillable([
            'subscription_status' => $status,
            'subscription_source' => SubscriptionSource::None,
            'subscription_plan_code' => $status === SubscriptionStatus::Expired ? null : 'pro',
        ]);

        expect(fn () => $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(14)))
            ->toThrow(InvalidSubscriptionStateException::class);

        // Nothing written, so `hasAccessibleBillingSubscription()` — which grants Trial
        // full access unconditionally — cannot be talked into reopening an ended
        // subscription that has no Mollie subscription behind it.
        expect($billable->fresh()->subscription_status)->toBe($status)
            ->and($billable->fresh()->trial_ends_at)->toBeNull()
            ->and($billable->fresh()->hasAccessibleBillingSubscription())->toBeFalse();
    }
});

it('still extends a trial for the states where one can run', function (): void {
    $until = BillingTime::nowUtc()->addDays(14);

    // Not `Active` on a Local subscription: that is a free plan or an access grant, with
    // no charge a trial could defer — see LocalTrialStateTest for what the flip cost.
    foreach ([SubscriptionStatus::New, SubscriptionStatus::Trial] as $status) {
        $billable = edgeBillable([
            'subscription_status' => $status,
            'subscription_source' => SubscriptionSource::Local,
            'subscription_plan_code' => 'pro',
            'subscription_interval' => SubscriptionInterval::Monthly,
            'trial_ends_at' => $status === SubscriptionStatus::Trial ? BillingTime::nowUtc()->addDay() : null,
        ]);

        $billable->extendBillingTrialUntil($until);

        expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial)
            ->and($billable->fresh()->trial_ends_at->toDateString())->toBe($until->toDateString());
    }
});

it('clears the dunning episode when a past-due trial is extended', function (): void {
    $billable = edgeBillable([
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->subDay(),
        'subscription_meta' => [
            'past_due_since' => BillingTime::nowUtc()->subDays(25)->toIso8601String(),
            'payment_failure' => ['payment_id' => 'tr_old', 'reason' => 'insufficient_funds'],
        ],
    ]);

    $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(14));

    $meta = $billable->fresh()->getBillingSubscriptionMeta();

    // "Give them another week to add a card" must not leave the auto-cancel clock
    // running against the old failure — the extension would be undone the moment the
    // new trial lapsed, because the expiry pass re-stamps with `??`.
    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($meta['past_due_since'] ?? null)->toBeNull()
        ->and($meta['payment_failure'] ?? null)->toBeNull();
});
