<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

it('reads trial_days strictly from the interval scope', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 14);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly.trial_days', 30);

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('pro', 'monthly'))->toBe(14);
    expect($catalog->trialDays('pro', 'yearly'))->toBe(30);
});

it('returns 0 when the interval has no trial_days configured', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly', [
        'base_price_net' => 1000,
        'seat_price_net' => null,
        'included_usages' => [],
        'usage_overage_prices' => [],
    ]);

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('pro', 'monthly'))->toBe(0);
});

it('does not fall back to plan-level trial_days', function (): void {
    config()->set('mollie-billing-plans.plans.pro.trial_days', 14);
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly', [
        'base_price_net' => 1000,
        'seat_price_net' => null,
        'included_usages' => [],
        'usage_overage_prices' => [],
    ]);

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('pro', 'monthly'))->toBe(0);
});

it('clamps negative values to 0', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', -5);

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('pro', 'monthly'))->toBe(0);
});

it('returns 0 for unknown plan or interval', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('does-not-exist', 'monthly'))->toBe(0);
    expect($catalog->trialDays('pro', 'weekly'))->toBe(0);
});

it('reads independently per interval — monthly may have a trial while yearly does not', function (): void {
    config()->set('mollie-billing-plans.plans.pro.intervals.monthly.trial_days', 7);
    // yearly intentionally has no trial_days
    $yearly = config('mollie-billing-plans.plans.pro.intervals.yearly');
    unset($yearly['trial_days']);
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly', $yearly);

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->trialDays('pro', 'monthly'))->toBe(7);
    expect($catalog->trialDays('pro', 'yearly'))->toBe(0);
});
