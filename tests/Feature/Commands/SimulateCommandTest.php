<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Notifications\TrialEndingSoonNotification;
use GraystackIT\MollieBilling\Notifications\TrialExpiredNotification;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    \GraystackIT\MollieBilling\MollieBilling::notifyBillingAdminsUsing(function ($billable): array {
        return [$billable];
    });
});

afterEach(function (): void {
    $reflection = new \ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class);
    $prop = $reflection->getProperty('notifyBillingAdminsCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function makeSimBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create(array_merge([
        'name' => 'Sim '.uniqid(),
        'email' => uniqid('sim-').'@x.test',
        'billing_country' => 'AT',
    ], $overrides));

    return $b->refresh();
}

it('refuses to run in production', function (): void {
    app()['env'] = 'production';

    $this->artisan('billing:simulate', ['flow' => 'trial-expired', '--billable' => 1])
        ->expectsOutputToContain('disabled in production')
        ->assertFailed();

    app()['env'] = 'testing';
});

it('rejects an unknown flow name', function (): void {
    $this->artisan('billing:simulate', ['flow' => 'bogus', '--billable' => 1])
        ->expectsOutputToContain('Unknown flow')
        ->assertFailed();
});

it('runs trial-expired flow non-interactively and flips billable to PastDue', function (): void {
    $b = makeSimBillable();
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'trial_ends_at' => BillingTime::nowUtc()->copy()->addDays(5), // future — simulator will push to past
    ])->save();

    $this->artisan('billing:simulate', [
        'flow' => 'trial-expired',
        '--billable' => $b->getKey(),
    ])->assertSuccessful();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::PastDue);
    Notification::assertSentTo($b, TrialExpiredNotification::class);
});

it('runs trial-ending-soon flow and sends the ending-soon notification', function (): void {
    $b = makeSimBillable();
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    $this->artisan('billing:simulate', [
        'flow' => 'trial-ending-soon',
        '--billable' => $b->getKey(),
    ])->assertSuccessful();

    Notification::assertSentTo($b, TrialEndingSoonNotification::class);
});

it('runs cancelled-to-expired flow and flips Cancelled billable to Expired', function (): void {
    $b = makeSimBillable([
        'mollie_customer_id' => 'cst_x',
        'mollie_mandate_id' => 'mdt_x',
    ]);
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    $this->artisan('billing:simulate', [
        'flow' => 'cancelled-to-expired',
        '--billable' => $b->getKey(),
    ])->assertSuccessful();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Expired);
});

it('runs past-due-auto-cancel flow and transitions PastDue → Cancelled', function (): void {
    config()->set('mollie-billing.past_due_max_days', 30);

    $b = makeSimBillable();
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    $this->artisan('billing:simulate', [
        'flow' => 'past-due-auto-cancel',
        '--billable' => $b->getKey(),
    ])->assertSuccessful();

    expect($b->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
});

it('fails the renewal flow when the billable has no mollie mandate', function (): void {
    $b = makeSimBillable(); // no mandate
    $b->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    $this->artisan('billing:simulate', [
        'flow' => 'renewal',
        '--billable' => $b->getKey(),
    ])
        ->expectsOutputToContain('No Mollie mandate')
        ->assertSuccessful(); // command itself returns SUCCESS, flow internally skipped
});

it('rejects a missing billable id with a clear error', function (): void {
    $this->artisan('billing:simulate', [
        'flow' => 'trial-expired',
        '--billable' => 99999,
    ])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

