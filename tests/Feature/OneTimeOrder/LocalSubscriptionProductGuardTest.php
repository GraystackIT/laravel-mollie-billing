<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\LocalSubscriptionCannotPurchaseProductsException;
use GraystackIT\MollieBilling\Services\Billing\StartOneTimeOrderCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.free', [
        'name' => 'Free',
        'tier' => 1,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
        ],
    ]);

    config()->set('mollie-billing-plans.products', [
        'token-pack' => [
            'name' => '500 Token Pack',
            'price_net' => 4900,
            'usage_type' => 'Tokens',
            'quantity' => 500,
        ],
    ]);
});

function makeLocalProductBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Free User',
        'email' => 'freeuser@example.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'subscription_meta' => ['seat_count' => 1],
    ])->save();

    return $billable->refresh();
}

it('blocks one-time orders for Local subscriptions when allow_one_time_orders is false (default)', function (): void {
    config()->set('mollie-billing.local_subscription.allow_one_time_orders', false);
    Mollie::shouldReceive('send')->never();

    $billable = makeLocalProductBillable();

    expect(fn () => app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'token-pack',
    ]))->toThrow(LocalSubscriptionCannotPurchaseProductsException::class);
});

it('allows one-time orders for Local subscriptions when allow_one_time_orders is true', function (): void {
    config()->set('mollie-billing.local_subscription.allow_one_time_orders', true);

    $billable = makeLocalProductBillable();

    Mollie::shouldReceive('send')
        ->once()
        ->andReturn(new class {
            public string $id = 'tr_local_ok';

            public function getCheckoutUrl(): string
            {
                return 'https://checkout.mollie.com/local-ok';
            }
        });

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable, [
        'product_code' => 'token-pack',
    ]);

    expect($result['payment_id'])->toBe('tr_local_ok')
        ->and($result['checkout_url'])->toBe('https://checkout.mollie.com/local-ok');
});

it('always allows one-time orders for Mollie subscriptions regardless of the flag', function (): void {
    config()->set('mollie-billing.local_subscription.allow_one_time_orders', false);

    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Paid User',
        'email' => 'paid@example.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(2),
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_x'],
        'mollie_customer_id' => 'cust_paid',
        'mollie_mandate_id' => 'mdt_paid',
    ])->save();

    Mollie::shouldReceive('send')
        ->once()
        ->andReturn(new class {
            public string $id = 'tr_paid';

            public function getCheckoutUrl(): string
            {
                return 'https://checkout.mollie.com/paid';
            }
        });

    $result = app(StartOneTimeOrderCheckout::class)->handle($billable->fresh(), [
        'product_code' => 'token-pack',
    ]);

    expect($result['payment_id'])->toBe('tr_paid');
});
