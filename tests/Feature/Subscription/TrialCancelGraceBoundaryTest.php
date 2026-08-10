<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => null, 'trial_days' => 30, 'included_usages' => []],
        ],
    ]);
});

function graceTrialBillable(string $interval, int $trialDays, int $daysIntoTrial = 2): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Trial Co',
        'email' => 'grace-trial@x.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_grace',
        'mollie_mandate_id' => 'mdt_grace',
    ]);

    // Mirrors the trial-activation webhook: the period starts when the mandate is
    // captured, so nextBillingDate() is one full (unpaid) interval out.
    $b->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays($daysIntoTrial),
        'trial_ends_at' => BillingTime::nowUtc()->addDays($trialDays - $daysIntoTrial),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_grace', 'seat_count' => 1],
    ])->save();

    return $b->refresh();
}

it('caps the graceful cancel of a yearly trial at trial_ends_at instead of granting a free year', function (): void {
    $billable = graceTrialBillable('yearly', trialDays: 30);
    $trialEnd = $billable->trial_ends_at;

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_grace', 'status' => 'canceled']);

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    // The bug: subscription_period_starts_at + 1 year, i.e. ~11 more months of free access.
    expect($billable->subscription_ends_at->diffInSeconds($trialEnd, true))->toBeLessThan(2);
    expect($billable->subscription_ends_at->isBefore(BillingTime::nowUtc()->addDays(29)))->toBeTrue();
});

it('caps the graceful cancel of a monthly trial at trial_ends_at', function (): void {
    $billable = graceTrialBillable('monthly', trialDays: 14);
    $trialEnd = $billable->trial_ends_at;

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_grace', 'status' => 'canceled']);

    $billable->cancelBillingSubscription();

    $billable->refresh();

    expect($billable->subscription_ends_at->diffInSeconds($trialEnd, true))->toBeLessThan(2);
});

it('keeps the full paid period as grace when the trial has already converted', function (): void {
    $billable = graceTrialBillable('monthly', trialDays: 14, daysIntoTrial: 20);
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(5),
        'trial_ends_at' => BillingTime::nowUtc()->subDays(15),
    ])->save();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_grace', 'status' => 'canceled']);

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    // An expired trial must not shorten a period the customer has actually paid for.
    expect($billable->subscription_ends_at->isAfter(BillingTime::nowUtc()->addDays(24)))->toBeTrue();
});

it('records the pre-cancel ends_at so a resume can tell a grant from an unlimited plan', function (): void {
    $billable = graceTrialBillable('monthly', trialDays: 14);

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_grace', 'status' => 'canceled']);

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->getBillingSubscriptionMeta())->toHaveKey('cancelled_from');
    expect($billable->getBillingSubscriptionMeta()['cancelled_from']['ends_at'])->toBeNull();
});

it('gives a trial longer than one interval the whole trial as grace', function (): void {
    // `trial_days` is per-interval config with no upper bound, and a 45-day trial on a
    // monthly plan is an ordinary thing to sell. `min(trial_ends_at, nextBillingDate())`
    // then picks the interval — so a customer cancelling on day two kept 30 of the 45 free
    // days they were promised. The cap exists to stop a *paid* interval being handed out
    // for free; during a trial there is no paid interval, only the trial.
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 45);

    $billable = graceTrialBillable('monthly', trialDays: 45);
    $trialEnd = $billable->trial_ends_at;

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_grace', 'status' => 'canceled']);

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->subscription_ends_at->diffInSeconds($trialEnd, true))->toBeLessThan(2)
        ->and($billable->subscription_ends_at->isAfter(BillingTime::nowUtc()->addDays(40)))->toBeTrue();
});
