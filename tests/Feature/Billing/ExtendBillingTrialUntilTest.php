<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialExtended;
use GraystackIT\MollieBilling\Services\Billing\MollieSubscriptionPatcher;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use GraystackIT\MollieBilling\Tests\Support\SpyMollieSubscriptionPatcher;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    SpyMollieSubscriptionPatcher::$calls = [];
    app()->bind(MollieSubscriptionPatcher::class, SpyMollieSubscriptionPatcher::class);
});

it('patches the Mollie startDate when called directly on a Mollie subscription', function (): void {
    Event::fake([TrialExtended::class]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $trialEnd = BillingTime::nowUtc()->addDays(3);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'trial_ends_at' => $trialEnd,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    $target = $trialEnd->copy()->addDays(10);

    $billable->extendBillingTrialUntil($target);

    $billable->refresh();
    expect($billable->trial_ends_at->diffInSeconds($target, false))->toBeBetween(-2, 2);

    $setCalls = array_values(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    ));
    expect($setCalls)->toHaveCount(1);

    $iso = \Carbon\Carbon::parse((string) $setCalls[0][3]['target']);
    expect($iso->diffInSeconds($target, false))->toBeBetween(-2, 2);

    Event::assertDispatched(TrialExtended::class);
});

it('does not call the Mollie patcher when the billable is Local', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Trial,
        'trial_ends_at' => BillingTime::nowUtc()->addDays(3),
    ])->save();

    $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(10));

    expect(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    ))->toBeEmpty();
});

it('keeps the existing trial end and skips Mollie when the caller passes an earlier target', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'X', 'email' => 'x@y.test']);
    $farEnd = BillingTime::nowUtc()->addDays(30);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc(),
        'trial_ends_at' => $farEnd,
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_customer_id' => 'cust_test',
    ])->save();

    // Caller passes an earlier target — max wins, so the effective end does not move
    // and we MUST NOT issue a redundant Mollie PATCH.
    $billable->extendBillingTrialUntil(BillingTime::nowUtc()->addDays(5));

    $billable->refresh();
    expect($billable->trial_ends_at->diffInSeconds($farEnd, false))->toBeBetween(-2, 2);

    expect(array_filter(
        SpyMollieSubscriptionPatcher::$calls,
        fn (array $c): bool => $c[0] === 'set_next_charge_date',
    ))->toBeEmpty();
});
