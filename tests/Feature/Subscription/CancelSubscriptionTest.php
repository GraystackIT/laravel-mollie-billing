<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCancelled;
use GraystackIT\MollieBilling\Services\Billing\CancelSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

function makeLocalSubscriber(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('immediately cancels a local subscription with ends_at = now', function (): void {
    Event::fake([SubscriptionCancelled::class]);

    $billable = makeLocalSubscriber();

    app(CancelSubscription::class)->handle($billable, immediately: true);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at)->not->toBeNull();
    expect($billable->subscription_ends_at->diffInSeconds(now()))->toBeLessThan(5);

    Event::assertDispatched(SubscriptionCancelled::class, function (SubscriptionCancelled $event): bool {
        return $event->immediately === true;
    });
});

it('gracefully cancels a local subscription, defaulting to a 30-day grace window', function (): void {
    Event::fake([SubscriptionCancelled::class]);

    $billable = makeLocalSubscriber();

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at)->not->toBeNull();
    // Spec: graceful Local cancel uses existing subscription_ends_at OR now+30 days.
    expect($billable->subscription_ends_at->isAfter(now()->addDays(29)))->toBeTrue();
    expect($billable->subscription_ends_at->isBefore(now()->addDays(31)))->toBeTrue();

    Event::assertDispatched(SubscriptionCancelled::class, function (SubscriptionCancelled $event): bool {
        return $event->immediately === false;
    });
});

it('preserves an existing subscription_ends_at on graceful local cancel', function (): void {
    $billable = makeLocalSubscriber();
    $existing = now()->addDays(10)->startOfSecond();
    $billable->forceFill(['subscription_ends_at' => $existing])->save();

    app(CancelSubscription::class)->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->subscription_ends_at->diffInSeconds($existing, true))->toBeLessThan(2);
});
