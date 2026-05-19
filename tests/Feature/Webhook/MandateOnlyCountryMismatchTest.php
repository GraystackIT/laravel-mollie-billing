<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Services\Billing\ResubscribeSubscription;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Regression: country mismatch detected during a mandate-only webhook must not
 * leave the billable un-activatable. The order in MandateOnlyPaymentHandler used
 * to run the country-match check before the trial/coupon activation persisted
 * plan_code/interval/source — so when the mismatch path triggered
 * cancelSubscription(immediately: false), the activation aborted via its
 * `status !== New` guard and left the billable in Cancelled with NULL plan_code,
 * making ResubscribeSubscription throw "plan or interval missing".
 */
class MandateOnlyCountryMismatchFakeWebhookController extends MollieWebhookController
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
    MandateOnlyCountryMismatchFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, MandateOnlyCountryMismatchFakeWebhookController::class);

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

function mismatchTrialBillable(string $declaredCountry = 'EE'): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'trial-mismatch@x.test',
        'name' => 'Mismatch Trial',
        'billing_country' => $declaredCountry,
        'tax_country_user' => $declaredCountry,
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b;
}

it('persists plan_code/interval/source even when the country-match check cancels the trial', function (): void {
    Notification::fake();

    $billable = mismatchTrialBillable('EE');

    // Mollie returns a sub for the trial activation, then a cancel for the mismatch flag.
    $capturedRequests = [];
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$capturedRequests) {
        $capturedRequests[] = $request;
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_trial_mismatch'];
        }
        if ($request instanceof CancelSubscriptionRequest) {
            return (object) ['id' => 'sub_trial_mismatch', 'status' => 'canceled'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    // countryCode = 'AT' contradicts the declared 'EE' → mismatch path.
    MandateOnlyCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mismatch_trial',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_trial_days' => 14,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mismatch_trial'])->assertStatus(200);

    $billable->refresh();

    // Subscription state landed BEFORE the country-check cancelled it.
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_period_starts_at)->not->toBeNull();

    // Mismatch was flagged → status is now Cancelled with a future ends_at.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at)->not->toBeNull();
    expect($billable->subscription_ends_at->isFuture())->toBeTrue();
    expect($billable->country_mismatch_flagged_at)->not->toBeNull();

    $mismatch = BillingCountryMismatch::query()
        ->where('billable_type', $billable->getMorphClass())
        ->where('billable_id', $billable->getKey())
        ->first();
    expect($mismatch)->not->toBeNull();
    expect($mismatch->status)->toBe(CountryMismatchStatus::Pending);
    expect($mismatch->tax_country_user)->toBe('EE');
    expect($mismatch->tax_country_payment)->toBe('AT');

    // Both Mollie requests were issued: CreateSubscription first, then CancelSubscription.
    expect($capturedRequests)->toHaveCount(2);
    expect($capturedRequests[0])->toBeInstanceOf(CreateSubscriptionRequest::class);
    expect($capturedRequests[1])->toBeInstanceOf(CancelSubscriptionRequest::class);

    // The critical recovery path: after the user resolves the mismatch, the
    // portal calls ResubscribeSubscription. Before this fix it threw
    // "Cannot resubscribe: plan or interval missing." because plan_code/interval
    // were never persisted. We don't actually call the real handle() (which would
    // hit Mollie again); we just assert the precondition guards in
    // ResubscribeSubscription would not throw.
    expect($billable->getBillingSubscriptionPlanCode())->toBe('pro');
    expect($billable->getBillingSubscriptionInterval())->toBe('monthly');
});

it('persists plan_code/interval/source for a coupon-trial (zero trial_days) with country mismatch', function (): void {
    Notification::fake();

    Coupon::create([
        'code' => 'FULL100',
        'name' => 'Full coverage',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 100,
    ]);

    $billable = mismatchTrialBillable('EE');

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_coupon_mismatch'];
        }
        if ($request instanceof CancelSubscriptionRequest) {
            return (object) ['id' => 'sub_coupon_mismatch', 'status' => 'canceled'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    MandateOnlyCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_mismatch_coupon',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_addon_codes' => [],
            'pending_subscription_extra_seats' => 0,
            'pending_subscription_trial_days' => 0,
            'pending_subscription_coupon_code' => 'FULL100',
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_mismatch_coupon'])->assertStatus(200);

    $billable->refresh();

    // Same expectations as the trial case: subscription state landed BEFORE the cancel.
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at?->isFuture())->toBeTrue();

    $mismatch = BillingCountryMismatch::query()->first();
    expect($mismatch)->not->toBeNull();
    expect($mismatch->status)->toBe(CountryMismatchStatus::Pending);
});

it('happy path: matching countries do not flag a mismatch and leave the trial active', function (): void {
    Notification::fake();

    $billable = mismatchTrialBillable('AT');

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_happy']);

    MandateOnlyCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_happy',
        'status' => 'paid',
        'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
        'metadata' => [
            'type' => 'mandate_only',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'pending_subscription_plan_code' => 'pro',
            'pending_subscription_interval' => 'monthly',
            'pending_subscription_trial_days' => 14,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_happy'])->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Trial);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->country_mismatch_flagged_at)->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('CancelSubscription preserves plan_code/interval/source so ResubscribeSubscription can recover after mismatch resolve', function (): void {
    // This is a service-level invariant test: lock in that the
    // cancel-at-period-end path used by CountryMatchService::flag() does NOT
    // clear plan_code/interval/source — otherwise the fix above would still
    // leave the billable un-resubscribable.
    Notification::fake();

    $billable = mismatchTrialBillable('AT');

    // Pretend the trial activation already ran successfully.
    $billable->forceFill([
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_period_starts_at' => now()->subDay(),
        'trial_ends_at' => now()->addDays(13),
        'subscription_meta' => ['mollie_subscription_id' => 'sub_test'],
        'mollie_mandate_id' => 'mdt_test',
    ])->save();

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_test', 'status' => 'canceled']);

    $cancel = app(\GraystackIT\MollieBilling\Services\Billing\CancelSubscription::class);
    $cancel->handle($billable, immediately: false);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_ends_at?->isFuture())->toBeTrue();

    // ResubscribeSubscription's precondition guards must not throw.
    $resubscribe = app(ResubscribeSubscription::class);
    expect(fn () => $resubscribe->handle($billable))->not->toThrow(\RuntimeException::class, 'Cannot resubscribe: plan or interval missing.');
});
