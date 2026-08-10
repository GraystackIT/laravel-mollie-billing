<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

function dedupTrialBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Dedup '.uniqid(),
        'email' => uniqid('dedup-').'@x.test',
        'billing_country' => 'AT',
    ], $overrides));

    $b->forceFill(array_merge([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Local,
        'subscription_plan_code' => 'starter',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay(),
    ], $overrides))->save();

    return $b->refresh();
}

it('warns about an ending trial exactly once, however often the pass runs', function (): void {
    // A queue re-delivery after retry_after, a second scheduler or a manual
    // queue:work --once all re-enter the same window. Before the marker existed,
    // each one mailed the customer again.
    $billable = dedupTrialBillable();

    (new ProcessTrialLifecycleJob)->handle();
    (new ProcessTrialLifecycleJob)->handle();
    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentToTimes($billable, TrialEndingSoonNotification::class, 1);

    expect($billable->refresh()->getBillingSubscriptionMeta()['trial_ending_notified_for'])
        ->toBe(BillingTime::nowUtc()->copy()->addDay()->toDateString());
});

it('warns again when the billable enters a second trial with a later end date', function (): void {
    // The marker holds the date, not a boolean, precisely so a re-trial is not
    // silently left unwarned.
    //
    // Done by travelling a month rather than by pushing `trial_ends_at` further out:
    // the notice window is always exactly tomorrow, so a *different* end date can
    // never also be inside it at the same instant. Pushing the date out instead made
    // this pass or fail depending on the clock — after 21:00 UTC, "tomorrow + 3h"
    // lands on the day after and is correctly not swept.
    $billable = dedupTrialBillable();

    (new ProcessTrialLifecycleJob)->handle();
    Notification::assertSentToTimes($billable, TrialEndingSoonNotification::class, 1);

    $this->travel(31)->days();

    // Second trial: same billable, a new end date that is tomorrow as of *now*.
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay(),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentToTimes($billable, TrialEndingSoonNotification::class, 2);

    expect($billable->refresh()->getBillingSubscriptionMeta()['trial_ending_notified_for'])
        ->toBe(BillingTime::nowUtc()->copy()->addDay()->toDateString());
});

it('leaves the marker unset when the notification throws, so the next run retries', function (): void {
    $billable = dedupTrialBillable();

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(function (): array {
        throw new RuntimeException('mail transport down');
    });

    (new ProcessTrialLifecycleJob)->handle();

    expect($billable->refresh()->getBillingSubscriptionMeta())
        ->not->toHaveKey('trial_ending_notified_for');

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn ($b): array => [$b]);

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentToTimes($billable, TrialEndingSoonNotification::class, 1);
});

it('does not let the marker interfere with the expiry pass', function (): void {
    // Expiry is keyed on status + trial_ends_at, never on the notice marker: a
    // billable that was warned yesterday must still expire today.
    $billable = dedupTrialBillable([
        'trial_ends_at' => BillingTime::nowUtc()->copy()->subHour(),
    ]);

    $billable->forceFill([
        'subscription_meta' => ['trial_ending_notified_for' => BillingTime::nowUtc()->copy()->subHour()->toDateString()],
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    expect($billable->refresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
});
