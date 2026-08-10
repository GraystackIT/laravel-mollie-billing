<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * FeatureAccess is a catalog lookup — "what does this plan include" — and stays
 * that way: consuming apps gate navigation and whole modules on hasPlanFeature(),
 * so making it also answer "may they use it" turns every billable that carries a
 * plan code but no live subscription dark.
 *
 * The plan code outliving the subscription was the actual defect, and the expiry
 * transition is where it is fixed: PrepareUsageOverageJob clears the plan-scoped
 * fields, after which there are no features left to resolve.
 */
beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
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
    return TestBillable::create(array_merge([
        'name' => 'Acme',
        'billing_country' => 'AT',
        'subscription_plan_code' => 'pro',
        'subscription_interval' => 'monthly',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
    ], $attributes));
}

it('resolves plan features from the plan code alone', function (): void {
    // No live subscription — the shape a consuming app's factories produce when
    // they only care about which plan an organization is on.
    $billable = featureBillable([
        'subscription_status' => SubscriptionStatus::New,
        'subscription_source' => SubscriptionSource::None,
    ]);

    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeTrue()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
});

it('keeps features while past_due so dunning does not black out the app', function (): void {
    $billable = featureBillable(['subscription_status' => SubscriptionStatus::PastDue]);

    // RequireActiveSubscription already keeps them out of the gated routes; the
    // feature catalog has no business making that call a second time.
    expect(app(FeatureAccess::class)->has($billable, 'api'))->toBeTrue()
        ->and($billable->hasAccessibleBillingSubscription())->toBeFalse();
});

it('drops the plan code when a lapsed access grant is swept to expired', function (): void {
    // ActivateLocalSubscription writes exactly this shape: Active plus an end date.
    $billable = featureBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->subDay(),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Expired)
        ->and($billable->subscription_plan_code)->toBeNull()
        ->and(app(FeatureAccess::class)->resolveFeatures($billable))->toBe([]);
});

it('leaves a running access grant untouched', function (): void {
    $billable = featureBillable([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(7),
    ]);

    PrepareUsageOverageJob::dispatchSync();

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and(app(FeatureAccess::class)->has($billable, 'api'))->toBeTrue();
});
