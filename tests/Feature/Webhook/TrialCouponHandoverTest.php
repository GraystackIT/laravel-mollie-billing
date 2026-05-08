<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

class TrialCouponHandoverFakeWebhookController extends MollieWebhookController
{
    public static ?object $nextPayment = null;

    protected function fetchPayment(string $paymentId): object
    {
        if (self::$nextPayment === null) {
            throw new \RuntimeException('No payment stub configured.');
        }

        return self::$nextPayment;
    }
}

beforeEach(function (): void {
    TrialCouponHandoverFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, TrialCouponHandoverFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1900,
                'seat_price_net' => null,
                'trial_days' => 14,
                'included_usages' => [],
            ],
        ],
    ]);
});

function trialCouponBillable(): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'trial-coup@x.test',
        'name' => 'Trial Coupon',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b;
}

function dispatchTrialMandateWebhook(TestBillable $billable, string $couponCode): void
{
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_trial_'.uniqid()];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    TrialCouponHandoverFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mandate_'.uniqid(),
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
            'pending_subscription_coupon_code' => $couponCode,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
    ];

    test()->postJson(route('billing.webhook'), ['id' => TrialCouponHandoverFakeWebhookController::$nextPayment->id])
        ->assertStatus(200);
}

it('parks a SinglePayment coupon in subscription_meta on trial activation', function (): void {
    Coupon::create([
        'code' => 'TRIAL10',
        'name' => 'Trial 10',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 10,
    ]);

    $billable = trialCouponBillable();
    dispatchTrialMandateWebhook($billable, 'TRIAL10');

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);

    $slot = $billable->getBillingSubscriptionMeta()['pending_first_charge_coupon'] ?? null;
    expect($slot)->not->toBeNull();
    expect($slot['code'])->toBe('TRIAL10');

    // Recurring marker is NOT set for SinglePayment coupons
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
});

it('sets the active_recurring_coupon marker for a Recurring coupon on trial activation', function (): void {
    Coupon::create([
        'code' => 'TRIAL_REC',
        'name' => 'Trial Recurring',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    $billable = trialCouponBillable();
    dispatchTrialMandateWebhook($billable, 'TRIAL_REC');

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);

    $marker = $billable->getBillingSubscriptionMeta()['active_recurring_coupon'] ?? null;
    expect($marker)->not->toBeNull();
    expect($marker['code'])->toBe('TRIAL_REC');
    expect($marker['discount_type'])->toBe(DiscountType::Percentage->value);
    expect($marker['discount_value'])->toBe(20);

    // SinglePayment slot is NOT set for Recurring coupons
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_first_charge_coupon');
});

it('activates the trial without coupon when the coupon code is unknown', function (): void {
    $billable = trialCouponBillable();
    dispatchTrialMandateWebhook($billable, 'DOES_NOT_EXIST');

    $billable->refresh();

    // Trial still activates — coupon failure is non-fatal
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);

    // Neither slot is set
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('pending_first_charge_coupon');
    expect($billable->getBillingSubscriptionMeta())->not->toHaveKey('active_recurring_coupon');
});
