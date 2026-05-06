<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\SubscriptionUpdateRequest;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.starter', [
        'name' => 'Starter',
        'tier' => 1,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
    config()->set('mollie-billing-plans.plans.business', [
        'name' => 'Business',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 3,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1900, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 19000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);
});

function makePastDueBillable(string $plan = 'starter'): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Acme',
        'email' => 'acme@example.test',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
        'mollie_mandate_id' => 'mdt_test',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::PastDue,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(40), // period long expired
        'subscription_meta' => [
            'seat_count' => 1,
            'mollie_subscription_id' => 'sub_test',
            'payment_failure' => [
                'payment_id' => 'tr_failed',
                'failed_at' => now()->subDays(5)->toIso8601String(),
                'reason' => 'card_declined',
            ],
        ],
    ])->save();

    return $billable->refresh();
}

it('Past-Due preview charges full new-plan price, no prorata factor', function (): void {
    $billable = makePastDueBillable('starter');

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'business',
        interval: 'monthly',
    ));

    expect($preview['isPastDueReset'])->toBeTrue();
    expect($preview['prorataFactor'])->toBe(1.0);
    // Business monthly = 1900 + 0 extra seats; full first period.
    expect($preview['prorataChargeNet'])->toBe($preview['newPriceNet']);
    expect($preview['prorataCreditNet'])->toBe(0);
});

it('Active preview keeps the regular prorata path (sanity check)', function (): void {
    $billable = makePastDueBillable('starter');
    $billable->forceFill([
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_period_starts_at' => now()->subDays(5),
    ])->save();

    $preview = app(PreviewService::class)->previewUpdate($billable->refresh(), new SubscriptionUpdateRequest(
        planCode: 'business',
        interval: 'monthly',
    ));

    expect($preview['isPastDueReset'])->toBeFalse();
    // Regular prorata: factor < 1.0 because only 25/30 days remain.
    expect($preview['prorataFactor'])->toBeLessThan(1.0);
});

it('Past-Due → Free preview sets isPastDueReset but results in zero charge', function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 0,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    $billable = makePastDueBillable('starter');

    $preview = app(PreviewService::class)->previewUpdate($billable, new SubscriptionUpdateRequest(
        planCode: 'free',
        interval: 'monthly',
    ));

    // Past-due flag is set (plan changed), but the charge math still resolves
    // to 0 because the new plan is free. UI just shows "no money flow".
    expect($preview['isPastDueReset'])->toBeTrue();
    expect($preview['prorataChargeNet'])->toBe(0);
});
