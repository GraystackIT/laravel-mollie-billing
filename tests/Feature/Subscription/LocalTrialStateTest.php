<?php

declare(strict_types=1);

use Carbon\Carbon;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

/**
 * "Local + Trial" — a trial with no Mollie subscription behind it.
 *
 * The signup path cannot produce it: a trial always starts from a captured mandate and a
 * Mollie subscription (`tests/Feature/Webhook/TrialActivationWebhookTest.php` asserts the
 * source). The one door that could was `extendBillingTrialUntil()`, which the admin panel
 * calls on whatever billable the operator has open. These tests pin what happens there,
 * and what happens to a row that is in the state anyway.
 */
beforeEach(function (): void {
    Notification::fake();
    config()->set('mollie-billing.billable_model', TestBillable::class);

    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free', 'tier' => 0, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
    ]);
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro', 'tier' => 2, 'included_seats' => 1, 'feature_keys' => [], 'allowed_addons' => [],
        'intervals' => ['monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []]],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function localTrialStateNights(TestBillable $billable, int $nights): TestBillable
{
    $from = BillingTime::nowUtc();

    for ($night = 1; $night <= $nights; $night++) {
        Carbon::setTestNow($from->copy()->addDays($night)->setTime(1, 0));
        ProcessTrialLifecycleJob::dispatchSync();
        PrepareUsageOverageJob::dispatchSync();
    }

    return $billable->fresh();
}

it('does not turn a free plan into a trial that lapses into past due', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Free Co', 'email' => 'free-trial@x.test']);
    app(ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');

    // The operator action, on a customer who is not on a trial at all: refused.
    expect(fn () => $billable->refresh()->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(7)))
        ->toThrow(InvalidSubscriptionStateException::class);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->fresh()->trial_ends_at)->toBeNull();

    // Flipped to Trial, the row lapsed a week later: "no payment method was added in
    // time", PastDue, locked out of a plan that costs nothing — and auto-cancelled and
    // expired a month after that.
    $billable = localTrialStateNights($billable, 60);

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->hasAccessibleBillingSubscription())->toBeTrue();
});

it('leaves an access grant on its own end date when an operator extends its "trial"', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Grant Co', 'email' => 'grant-trial@x.test']);
    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'monthly', [], 45);
    $grantEnd = $billable->fresh()->subscription_ends_at->toDateString();

    expect(fn () => $billable->refresh()->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(7)))
        ->toThrow(InvalidSubscriptionStateException::class);

    $billable = localTrialStateNights($billable, 20);

    // Still the grant it was: accessible, and ending when it was given to end.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($billable->subscription_ends_at->toDateString())->toBe($grantEnd);

    $billable = localTrialStateNights($billable, 40);

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Expired);
});

it('resumes a cancelled local trial as a trial, not as a paid plan nobody pays for', function (): void {
    // The state itself, however a row got into it (rows written by an older release, an
    // app's own code). Cancelled and resumed, it came back `Active` with no end date:
    // a paid plan, Local, for ever — the trial job only looks at status Trial.
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Legacy Co', 'email' => 'legacy-trial@x.test']);
    $trialEnd = BillingTime::nowUtc()->addDays(10);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(4),
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    app(CancelSubscription::class)->handle($billable->refresh(), immediately: false);
    expect($billable->fresh()->subscription_ends_at->toDateString())->toBe($trialEnd->toDateString());

    app(ResubscribeSubscription::class)->handle($billable->fresh());

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($billable->trial_ends_at->toDateString())->toBe($trialEnd->toDateString());

    // …and the trial then ends the way a trial without a payment method ends.
    $billable = localTrialStateNights($billable, 12);

    expect($billable->subscription_status)->toBe(SubscriptionStatus::PastDue)
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
});
