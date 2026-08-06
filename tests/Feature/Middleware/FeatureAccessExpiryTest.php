<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * The plan code outlives the subscription, so gating on it alone left
 * `@planFeature` and the `billing.feature` middleware open after expiry, after a
 * lapsed access grant, and while past_due.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'feature_keys' => ['api', 'export'],
        'intervals' => [
            'monthly' => ['price_net' => 2900, 'mollie_interval' => '1 month'],
        ],
    ]);
});

function featureBillable(array $attributes = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(array_merge([
        'name' => 'Acme',
        'billing_country' => 'AT',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => 'monthly',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
    ], $attributes));

    return $billable;
}

it('grants plan features while the subscription is active', function (): void {
    $billable = featureBillable();

    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeTrue();
});

it('grants no features once the subscription expired', function (): void {
    $billable = featureBillable(['subscription_status' => SubscriptionStatus::Expired]);

    expect(app(FeatureAccess::class)->resolveFeatures($billable))->toBe([]);
    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeFalse();
});

it('grants no features after an access grant lapsed', function (): void {
    // ActivateLocalSubscription writes exactly this shape: Active plus an end date.
    $billable = featureBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeFalse();
});

it('still grants features inside a running access grant', function (): void {
    $billable = featureBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(7),
    ]);

    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeTrue();
});

it('grants no features for a billable that never subscribed', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Nobody', 'billing_country' => 'AT']);

    expect(app(FeatureAccess::class)->resolveFeatures($billable))->toBe([]);
});
