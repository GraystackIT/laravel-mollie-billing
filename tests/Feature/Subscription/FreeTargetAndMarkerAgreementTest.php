<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Services\Billing\UpdateSubscription;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Storage;
use Mollie\Laravel\Facades\Mollie;

/**
 * Two rules that had one implementation too many.
 *
 * "Should the Mollie subscription be cancelled?" was `isFreePlan()` in the patcher and
 * `planChanged && isFreePlan()` in the orchestrator — and `isFreePlan()` looks at base and
 * seat prices only, so it says yes for a free plan that still carries a paid addon.
 *
 * "Is this recurring coupon still redeemable?" lived in `CouponService` and in a private
 * mirror in `ResubscribeSubscription` that read three marker fields and skipped every gate.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('mollie-billing.invoices.disk', 'local');

    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free',
            'tier' => 0,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['extra-storage', 'analytics'],
            'intervals' => [
                'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['extra-storage'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra-storage' => [
            'name' => 'Extra Storage',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 500]],
        ],
        'analytics' => [
            'name' => 'Analytics',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 300]],
        ],
    ]);
});

function freeTargetBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Free Target Ltd',
        'email' => uniqid('free-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'free',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(14),
        'active_addon_codes' => ['extra-storage', 'analytics'],
        'mollie_customer_id' => 'cst_free',
        'mollie_mandate_id' => 'mdt_free',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_free', 'seat_count' => 1],
    ], $overrides))->save();

    return $billable->refresh();
}

it('keeps a free plan with a paid addon on Mollie', function (): void {
    // 0 + 500 is not free. `isFreePlan('free')` says it is, so the patcher cancelled the
    // Mollie subscription while the orchestrator kept source Mollie, status Active and the
    // subscription id — the customer kept the addon and was never billed again, and the
    // next change patched a subscription that no longer existed.
    $billable = freeTargetBillable();

    $cancelled = false;
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$cancelled) {
        if ($request instanceof \Mollie\Api\Http\Requests\CancelSubscriptionRequest) {
            $cancelled = true;
        }

        return (object) ['id' => 'sub_free', 'status' => 'active'];
    });

    // Drop one of the two addons: 500 becomes 300, so the target still bills something
    // and the subscription has to survive — while `isFreePlan('free')` still says "free".
    app(UpdateSubscription::class)->update($billable, ['addons' => ['analytics' => 1]]);

    expect($cancelled)->toBeFalse()
        ->and($billable->fresh()->subscription_source)->toBe(SubscriptionSource::Mollie);
});

it('cancels at Mollie when the target configuration bills nothing at all', function (): void {
    // Free plan, addon removed: now it genuinely bills nothing, so the subscription has to
    // go — and the local source has to follow it, which is the half that used to disagree.
    $billable = freeTargetBillable();

    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_free', 'status' => 'canceled']);

    app(UpdateSubscription::class)->update($billable, ['addons' => []]);

    expect($billable->fresh()->subscription_source)->toBe(SubscriptionSource::Local);
});

it('refuses to re-apply a recurring coupon whose campaign is switched off', function (): void {
    $coupon = Coupon::create([
        'code' => 'WELCOME50',
        'name' => 'Welcome 50',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
        'active' => false,   // deactivated — the documented way to stop a campaign
        'stackable' => false,
        'redemptions_count' => 1,
    ]);

    $billable = freeTargetBillable([
        'subscription_plan_code' => 'pro',
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_ends_at' => BillingTime::nowUtc()->addDays(16),
        'active_addon_codes' => [],
        'subscription_meta' => [
            'mollie_subscription_id' => 'sub_free',
            'seat_count' => 1,
            // The marker survives a cancellation and is only cleared on a renewal, which
            // never happens during a grace period.
            'active_recurring_coupon' => [
                'coupon_id' => $coupon->id,
                'code' => 'WELCOME50',
                'discount_type' => 'percentage',
                'discount_value' => 50,
                'base_amount_net' => 2900,
            ],
        ],
    ]);

    $created = [];
    Mollie::shouldReceive('setIdempotencyKey')->withAnyArgs()->andReturnSelf();
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof \Mollie\Api\Http\Requests\CreateSubscriptionRequest) {
            $created[] = (new ReflectionObject($request))->getProperty('amount')->getValue($request)->value;
        }

        return (object) ['id' => 'sub_new', 'status' => 'active'];
    });

    app(ResubscribeSubscription::class)->handle($billable);

    // Full price. The private mirror read discount_type/value/base only and re-applied the
    // switched-off campaign, so Mollie collected half price and the renewal handler — which
    // does ask CouponService — then fired PaymentAmountMismatch on it.
    expect($created)->toHaveCount(1)
        ->and($created[0])->toBe('34.80');
});
