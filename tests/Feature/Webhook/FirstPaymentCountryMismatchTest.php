<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Mollie\Api\Http\Requests\CancelSubscriptionRequest;
use Mollie\Api\Http\Requests\CreateSubscriptionRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Regression: country mismatch during a real first-payment (or local→Mollie
 * upgrade) was previously run from inside FirstPaymentArtifacts::persist(),
 * before CreateSubscription had set plan_code/interval/source AND before the
 * final status=Active forceFill. The mismatch's cancel-at-period-end would
 * therefore be silently overwritten by the status=Active that ran afterwards.
 *
 * Fix: callers run the country-match check AFTER the activation has fully
 * landed, so the cancel-at-period-end survives.
 */
class FirstPaymentCountryMismatchFakeWebhookController extends MollieWebhookController
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
    FirstPaymentCountryMismatchFakeWebhookController::$nextPayment = null;
    $this->app->bind(MollieWebhookController::class, FirstPaymentCountryMismatchFakeWebhookController::class);

    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 1,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => [
                'base_price_net' => 1000,
                'seat_price_net' => null,
                'trial_days' => 0,
                'included_usages' => [],
            ],
        ],
    ]);
});

function firstPaymentMismatchBillable(string $declaredCountry = 'EE'): TestBillable
{
    $b = new TestBillable;
    $b->forceFill([
        'email' => 'first-mismatch@x.test',
        'name' => 'First Mismatch',
        'billing_country' => $declaredCountry,
        'tax_country_user' => $declaredCountry,
        'mollie_customer_id' => 'cst_test',
    ])->save();

    return $b;
}

it('cancels-at-period-end and keeps the flag when first-payment lands with mismatched country', function (): void {
    Notification::fake();

    $billable = firstPaymentMismatchBillable('EE');

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_first_mismatch'];
        }
        if ($request instanceof CancelSubscriptionRequest) {
            return (object) ['id' => 'sub_first_mismatch', 'status' => 'canceled'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    // First payment for a paid plan (no trial). countryCode AT contradicts EE.
    FirstPaymentCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_mismatch',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'addon_codes' => [],
            'extra_seats' => 0,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_mismatch'])->assertStatus(200);

    $billable->refresh();

    // Subscription state landed correctly.
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_interval)->toBe(SubscriptionInterval::Monthly);
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);

    // Mismatch flagged AFTER status=Active was set — cancel-at-period-end now wins.
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at?->isFuture())->toBeTrue();
    expect($billable->country_mismatch_flagged_at)->not->toBeNull();

    $mismatch = BillingCountryMismatch::query()->first();
    expect($mismatch)->not->toBeNull();
    expect($mismatch->status)->toBe(CountryMismatchStatus::Pending);
    expect($mismatch->tax_country_user)->toBe('EE');
    expect($mismatch->tax_country_payment)->toBe('AT');
});

it('happy path: matching country during first-payment leaves the subscription Active', function (): void {
    Notification::fake();

    $billable = firstPaymentMismatchBillable('AT');

    Mollie::shouldReceive('send')->andReturn((object) ['id' => 'sub_first_happy']);

    FirstPaymentCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_first_happy',
        'status' => 'paid',
        'amount' => (object) ['value' => '12.00', 'currency' => 'EUR'],
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'pro',
            'interval' => 'monthly',
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_first_happy'])->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->country_mismatch_flagged_at)->toBeNull();
    expect(BillingCountryMismatch::count())->toBe(0);
});

it('cancels-at-period-end when a local→Mollie upgrade payment lands with mismatched country', function (): void {
    Notification::fake();

    $billable = firstPaymentMismatchBillable('EE');
    // Pretend the billable is on a local (free) plan and is upgrading.
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
    ])->save();

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) {
        if ($request instanceof CreateSubscriptionRequest) {
            return (object) ['id' => 'sub_upgrade_mismatch'];
        }
        if ($request instanceof CancelSubscriptionRequest) {
            return (object) ['id' => 'sub_upgrade_mismatch', 'status' => 'canceled'];
        }
        throw new \LogicException('Unexpected: '.get_class($request));
    });

    FirstPaymentCountryMismatchFakeWebhookController::$nextPayment = (object) [
        'id' => 'tr_upgrade_mismatch',
        'status' => 'paid',
        'amount' => (object) ['value' => '11.90', 'currency' => 'EUR'],
        'metadata' => [
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'plan_code' => 'pro',
            'interval' => 'monthly',
            'upgrade_from_local' => true,
        ],
        'subscriptionId' => null,
        'customerId' => 'cst_test',
        'mandateId' => 'mdt_test',
        'countryCode' => 'AT',
    ];

    $this->postJson(route('billing.webhook'), ['id' => 'tr_upgrade_mismatch'])->assertStatus(200);

    $billable->refresh();

    expect($billable->subscription_plan_code)->toBe('pro');
    expect($billable->subscription_source)->toBe(SubscriptionSource::Mollie);
    expect($billable->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($billable->subscription_ends_at?->isFuture())->toBeTrue();

    expect(BillingCountryMismatch::query()->where('status', CountryMismatchStatus::Pending)->count())->toBe(1);
});
