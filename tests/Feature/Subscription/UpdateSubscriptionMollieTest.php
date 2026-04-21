<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Events\PlanChangePending;
use GraystackIT\MollieBilling\Exceptions\InvalidSubscriptionStateException;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\PreviewService;
use GraystackIT\MollieBilling\Services\Billing\ScheduleSubscriptionChange;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Services\Billing\ValidateSubscriptionChange;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Services\Wallet\WalletUsageService;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Event;

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

    protected function chargeProrataImmediate(\GraystackIT\MollieBilling\Contracts\Billable $billable, int $prorataChargeNet, ?\GraystackIT\MollieBilling\Services\Billing\SubscriptionChangeContext $context = null): void
    {
        self::$calls[] = ['prorata_charge', $prorataChargeNet];

        // Simulate storing the prorata_pending_payment_id like the real method does.
        if ($billable instanceof \Illuminate\Database\Eloquent\Model) {
            $meta = $billable->getBillingSubscriptionMeta();
            $meta['prorata_pending_payment_id'] = 'tr_test_'.uniqid();
            $billable->forceFill(['subscription_meta' => $meta])->save();
        }
    }

    protected function refundProrataCredit(\GraystackIT\MollieBilling\Contracts\Billable $billable, int $prorataCreditNet, ?\GraystackIT\MollieBilling\Services\Billing\SubscriptionChangeContext $context = null): void
    {
        self::$calls[] = ['prorata_refund', $prorataCreditNet];
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
            $app->make(ValidateSubscriptionChange::class),
            $app->make(ScheduleSubscriptionChange::class),
            $app->make(WalletUsageService::class),
        );
    });

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'trial_days' => 0,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 990, 'included_usages' => []],
            'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 9900, 'included_usages' => []],
        ],
    ]);
});

function makeMollieSubBillable(string $plan = 'free', string $interval = 'monthly'): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'mollie@example.com',
        'name' => 'Mollie Sub',
        'billing_country' => 'DE',
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_plan_code' => $plan,
        'subscription_interval' => SubscriptionInterval::from($interval),
        'subscription_period_starts_at' => now(),
        'mollie_customer_id' => 'cst_123',
        'mollie_mandate_id' => 'mdt_123',
        'subscription_meta' => ['seat_count' => 1, 'mollie_subscription_id' => 'sub_old_999'],
    ])->save();

    return $b->refresh();
}

it('stores pending plan change for Mollie upgrade and does not apply immediately', function (): void {
    Event::fake([PlanChangePending::class]);

    $b = makeMollieSubBillable();

    $result = app(UpdateSubscription::class)->update($b, ['plan_code' => 'pro', 'interval' => 'monthly']);

    // Plan should NOT be changed yet.
    $b->refresh();
    expect($b->subscription_plan_code)->toBe('free');
    expect($result['pendingPaymentConfirmation'])->toBeTrue();
    expect($result['planChanged'])->toBeFalse();

    // Prorata charge was called, but NOT cancel+create.
    $callTypes = array_column(SpyUpdateSubscription::$calls, 0);
    expect($callTypes)->toContain('prorata_charge');
    expect($callTypes)->not->toContain('cancel');
    expect($callTypes)->not->toContain('create');

    // Pending change stored in meta.
    $meta = $b->getBillingSubscriptionMeta();
    expect($meta['pending_plan_change'])->not->toBeNull();
    expect($meta['pending_plan_change']['plan_code'])->toBe('pro');

    Event::assertDispatched(PlanChangePending::class);
});

it('applies pending plan change and cancels+recreates Mollie subscription', function (): void {
    $b = makeMollieSubBillable();

    // Store a pending change.
    $meta = $b->getBillingSubscriptionMeta();
    $meta['pending_plan_change'] = [
        'current_plan' => 'free',
        'current_interval' => 'monthly',
        'current_seats' => 1,
        'current_addons' => [],
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'seats' => 1,
        'addons' => [],
        'new_net' => 2900,
        'prorata_charge_net' => 1450,
        'coupon_code' => null,
        'requested_at' => now()->toIso8601String(),
    ];
    $meta['prorata_pending_payment_id'] = 'tr_test_123';
    $b->forceFill(['subscription_meta' => $meta])->save();

    $result = app(UpdateSubscription::class)->applyPendingPlanChange($b);

    $b->refresh();
    expect($b->subscription_plan_code)->toBe('pro');
    expect($result['planChanged'])->toBeTrue();

    // cancel+create should have been called.
    $callTypes = array_column(SpyUpdateSubscription::$calls, 0);
    expect($callTypes)->toContain('cancel');
    expect($callTypes)->toContain('create');

    // Pending state should be cleared.
    $meta = $b->getBillingSubscriptionMeta();
    expect($meta['pending_plan_change'] ?? null)->toBeNull();
    expect($meta['prorata_pending_payment_id'] ?? null)->toBeNull();
    expect($meta['mollie_subscription_id'])->toStartWith('sub_new_');
});

it('clears pending plan change without applying', function (): void {
    $b = makeMollieSubBillable();

    $meta = $b->getBillingSubscriptionMeta();
    $meta['pending_plan_change'] = ['plan_code' => 'pro', 'interval' => 'monthly'];
    $meta['prorata_pending_payment_id'] = 'tr_test_123';
    $b->forceFill(['subscription_meta' => $meta])->save();

    app(UpdateSubscription::class)->clearPendingPlanChange($b);

    $b->refresh();
    $meta = $b->getBillingSubscriptionMeta();

    // Plan unchanged.
    expect($b->subscription_plan_code)->toBe('free');

    // Pending state cleared.
    expect($meta['pending_plan_change'] ?? null)->toBeNull();
    expect($meta['prorata_pending_payment_id'] ?? null)->toBeNull();
});

it('throws when a second plan change is attempted while one is pending', function (): void {
    $b = makeMollieSubBillable();

    $meta = $b->getBillingSubscriptionMeta();
    $meta['pending_plan_change'] = ['plan_code' => 'pro', 'interval' => 'monthly'];
    $b->forceFill(['subscription_meta' => $meta])->save();

    app(UpdateSubscription::class)->update($b, ['plan_code' => 'pro', 'interval' => 'monthly']);
})->throws(InvalidSubscriptionStateException::class);

it('applies downgrade immediately without pending state', function (): void {
    $b = makeMollieSubBillable('pro', 'monthly');

    $result = app(UpdateSubscription::class)->update($b, ['plan_code' => 'free', 'interval' => 'monthly']);

    $b->refresh();
    expect($b->subscription_plan_code)->toBe('free');
    expect($result['planChanged'])->toBeTrue();
    expect($result['pendingPaymentConfirmation'] ?? false)->toBeFalse();

    // Prorata refund should have been called.
    $callTypes = array_column(SpyUpdateSubscription::$calls, 0);
    expect($callTypes)->toContain('prorata_refund');
});
