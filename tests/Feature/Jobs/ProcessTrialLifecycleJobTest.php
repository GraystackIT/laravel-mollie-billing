<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\TrialConverted;
use GraystackIT\MollieBilling\Events\TrialExpired;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([TrialConverted::class, TrialExpired::class]);

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(function ($billable): array {
        return [$billable];
    });
});

afterEach(function (): void {
    // Reset the static callback so it does not leak into other tests where
    // TestBillable is not a Notifiable and the default (admin recipients via
    // notifyAdminUsing) is expected.
    $reflection = new \ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class);
    $prop = $reflection->getProperty('notifyBillingAdminsCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function makeTrialingBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Trial '.uniqid(),
        'email' => uniqid('trial-').'@x.test',
        'billing_country' => 'AT',
    ], $overrides));

    return $b->refresh();
}

it('sends TrialEndingSoonNotification (mandate variant) and does NOT fire TrialConverted for billable with mandate whose trial ends tomorrow', function (): void {
    // The actual conversion (TrialConverted event + TrialConvertedNotification)
    // must wait until SubscriptionPaymentHandler::paid() — i.e. when Mollie's
    // first recurring charge actually lands. The lifecycle job only sends an
    // ending-soon heads-up; the notification itself picks the with-mandate
    // wording because hasMollieMandate() is true.
    $billable = makeTrialingBillable([
        'mollie_customer_id' => 'cst_t',
        'mollie_mandate_id' => 'mdt_t',
    ]);

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay()->addHours(2),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentTo($billable, TrialEndingSoonNotification::class, function (TrialEndingSoonNotification $n) use ($billable): bool {
        $payload = $n->toArray($billable);

        return ($payload['has_mandate'] ?? null) === true;
    });
    Event::assertNotDispatched(TrialConverted::class);

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial);
});

it('sends TrialEndingSoonNotification for billable WITHOUT mandate whose trial ends tomorrow', function (): void {
    $billable = makeTrialingBillable();

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay()->addHours(2),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentTo($billable, TrialEndingSoonNotification::class);
    Event::assertNotDispatched(TrialConverted::class);
});

it('flips a trial billable to PastDue and notifies once trial_ends_at has passed', function (): void {
    $billable = makeTrialingBillable();

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->subDay(),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);

    Notification::assertSentTo($billable, TrialExpiredNotification::class);
    Event::assertDispatched(TrialExpired::class);
});

it('does not flip an active billable whose trial_ends_at lies in the past', function (): void {
    // E.g. customer converted manually — status is already Active, the historical
    // trial_ends_at is preserved. Job must leave them alone.
    $billable = makeTrialingBillable();

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->subDays(5),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
    Event::assertNotDispatched(TrialExpired::class);
});

it('honors trial_ending_soon_notice_days when set to a longer notice window', function (): void {
    config()->set('mollie-billing.trial_ending_soon_notice_days', 7);

    $inWindow = makeTrialingBillable();
    $inWindow->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDays(7)->addHours(2),
    ])->save();

    $tomorrow = makeTrialingBillable();
    $tomorrow->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDay()->addHours(2),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    Notification::assertSentTo($inWindow, TrialEndingSoonNotification::class);
    Notification::assertNotSentTo($tomorrow, TrialEndingSoonNotification::class);
});

it('does not touch a trial billable whose trial is still in the future', function (): void {
    $billable = makeTrialingBillable();

    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDays(7),
    ])->save();

    (new ProcessTrialLifecycleJob)->handle();

    expect($billable->fresh()->subscription_status)->toBe(SubscriptionStatus::Trial);
    Event::assertNotDispatched(TrialExpired::class);
    Event::assertNotDispatched(TrialConverted::class);
});
