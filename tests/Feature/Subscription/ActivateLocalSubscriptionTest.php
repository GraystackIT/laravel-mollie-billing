<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Events\SubscriptionCreated;
use GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 5,
        'included_usages' => ['emails' => 100, 'sms' => 50],
        'feature_keys' => ['dashboard', 'pro-feature'],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000],
        ],
    ]);
});

it('activates a local subscription with all expected fields', function (): void {
    Event::fake([SubscriptionCreated::class]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'monthly', ['print-gateway']);

    $billable->refresh();

    expect($billable->subscription_source)->toBe(SubscriptionSource::Local);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->active_addon_codes)->toBe(['print-gateway']);
    expect($billable->subscription_period_starts_at)->not->toBeNull();
    expect($billable->subscription_ends_at)->toBeNull();
    expect($billable->subscription_meta['seat_count'] ?? null)->toBe(5);

    Event::assertDispatched(SubscriptionCreated::class, function (SubscriptionCreated $event) use ($billable): bool {
        return $event->billable->getKey() === $billable->getKey()
            && $event->planCode === 'pro'
            && $event->interval === 'monthly';
    });
});

it('initialises wallets to the included quotas', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'monthly');

    $billable->refresh();

    expect($billable->getWallet('emails')?->balanceInt)->toBe(100);
    expect($billable->getWallet('sms')?->balanceInt)->toBe(50);
});

it('sets subscription_ends_at when a duration is given', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    app(ActivateLocalSubscription::class)->handle($billable, 'pro', 'yearly', [], 30);

    $billable->refresh();

    expect($billable->subscription_ends_at)->not->toBeNull();
    expect($billable->subscription_ends_at->isAfter(now()->addDays(29)))->toBeTrue();
    expect($billable->subscription_ends_at->isBefore(now()->addDays(31)))->toBeTrue();
});
