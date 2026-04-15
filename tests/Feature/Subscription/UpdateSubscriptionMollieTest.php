<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\ChargeUsageOverageDirectly;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * Subclass that spies on Mollie cancel+create calls without hitting the API.
 */
class SpyUpdateSubscription extends UpdateSubscription
{
    public static array $calls = [];

    protected function mollieCancelSubscription(string $customerId, string $subscriptionId): void
    {
        self::$calls[] = ['cancel', $customerId, $subscriptionId];
    }

    protected function mollieCreateSubscription(string $customerId, array $payload): object
    {
        self::$calls[] = ['create', $customerId, $payload];

        return (object) ['id' => 'sub_new_'.uniqid()];
    }
}

beforeEach(function (): void {
    SpyUpdateSubscription::$calls = [];

    $this->app->bind(UpdateSubscription::class, function ($app): UpdateSubscription {
        return new SpyUpdateSubscription(
            $app->make(CouponService::class),
            $app->make(PreviewService::class),
            $app->make(SubscriptionCatalogInterface::class),
            $app->make(VatCalculationService::class),
            $app->make(ChargeUsageOverageDirectly::class),
            $app->make(ScheduleSubscriptionChange::class),
        );
    });

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'included_usages' => [],
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 990],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 9900],
        ],
    ]);
});

it('cancels and recreates the Mollie subscription on plan change', function (): void {
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'mollie@example.com',
        'name' => 'Mollie Sub',
        'billing_country' => 'DE',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now(),
        'mollie_customer_id' => 'cst_123',
        'mollie_mandate_id' => 'mdt_123',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_old_999'],
    ])->save();

    $result = app(UpdateSubscription::class)->update($b, ['plan_code' => 'pro', 'interval' => 'monthly']);

    expect($result['planChanged'])->toBeTrue();
    expect($result['mollieSubscriptionPatched'])->toBeTrue();
    expect(count(SpyUpdateSubscription::$calls))->toBe(2);
    expect(SpyUpdateSubscription::$calls[0][0])->toBe('cancel');
    expect(SpyUpdateSubscription::$calls[1][0])->toBe('create');

    $b->refresh();
    expect($b->subscription_meta['mollie_subscription_id'])->toStartWith('sub_new_');
});
