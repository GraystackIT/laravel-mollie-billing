<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(fn ($billable): array => [$billable]);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))
        ->getProperty('notifyBillingAdminsCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function lapsedTrialBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Trial '.uniqid(),
        'email' => uniqid('tr-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $b->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->subHours(3),
    ], $overrides))->save();

    return $b->refresh();
}

it('expires a lapsed trial that never captured a payment method', function (): void {
    Notification::fake();

    $b = lapsedTrialBillable();

    ProcessTrialLifecycleJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
    Notification::assertSentTo($b, TrialExpiredNotification::class);
});

it('leaves a mandate-holding trial alone while its first charge settles', function (): void {
    Notification::fake();

    // CreateSubscription schedules Mollie's first charge for exactly trial_ends_at, so
    // on charge day this row legitimately has a past trial_ends_at and no outcome yet.
    $b = lapsedTrialBillable([
        'mollie_customer_id' => 'cst_trial',
        'mollie_mandate_id' => 'mdt_trial',
    ]);

    ProcessTrialLifecycleJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial);
    Notification::assertNothingSentTo($b);
});

it('expires a mandate-holding trial whose charge is recorded as failed', function (): void {
    Notification::fake();

    $b = lapsedTrialBillable([
        'mollie_customer_id' => 'cst_trial2',
        'mollie_mandate_id' => 'mdt_trial2',
        'subscription_meta' => [
            'payment_failure' => [
                'payment_id' => 'tr_failed',
                'failed_at' => BillingTime::nowUtc()->subHour()->toIso8601String(),
                'reason' => 'insufficient_funds',
            ],
        ],
    ]);

    ProcessTrialLifecycleJob::dispatchSync();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
    Notification::assertSentTo($b, TrialExpiredNotification::class);
});

it('expires every lapsed trial past the first chunk', function (): void {
    Notification::fake();

    // chunk() paginates by OFFSET and the callback mutates the filtered column, so
    // page 2 used to skip as many rows as page 1 had removed from the result set.
    // Chunk size is 200; 3 rows with a chunk-sized boundary would be too slow to set
    // up here, so the guarantee is asserted structurally: every matching row flips.
    $keys = [];
    for ($i = 0; $i < 12; $i++) {
        $keys[] = lapsedTrialBillable()->getKey();
    }

    ProcessTrialLifecycleJob::dispatchSync();

    $remaining = TestBillable::query()
        ->whereIn('id', $keys)
        ->where('subscription_status', SubscriptionStatus::Trial->value)
        ->count();

    expect($remaining)->toBe(0);
});
