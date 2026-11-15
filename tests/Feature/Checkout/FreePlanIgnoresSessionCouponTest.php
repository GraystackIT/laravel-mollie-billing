<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Webhook\MandateOnlyPaymentHandler;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

// A promotion link (PromotionController) stores its coupon under the session key
// `billing.auto_apply_coupon`. Nothing ever removes it.
//
// StartSubscriptionCheckout::handle() adopts that code for every checkout of the session,
// and its zero-amount branch reads "free plan AND no coupon → activate locally". With the
// session coupon present the free plan therefore is NOT activated: the customer is sent to
// Mollie to authorise a payment method for a plan that costs nothing, and when that €0
// payment settles, MandateOnlyPaymentHandler refuses the activation because a coupon cannot
// cover 100 % of an order of 0 (`order_amount_net <= 0`).
//
// Result: mandate captured, no subscription — and every retry in the same session takes
// the same path. The captured mandate also costs the customer the trial on any later paid
// checkout (`! hasMollieMandate()` is the trial gate).
//
// The mismatch guard that drops a session coupon the amount was not priced with sits
// further down, in the paid branch only.

beforeEach(function (): void {
    Notification::fake();

    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
});

it('activates the free plan although a promotion coupon is still in the session', function (): void {
    app(CouponService::class)->create([
        'code' => 'WELCOME20',
        'name' => 'Welcome 20 %',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);

    // What PromotionController does for /promotion/{token}.
    Session::put('billing.auto_apply_coupon', 'WELCOME20');

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Free Co', 'email' => 'free@x.test', 'billing_country' => 'AT']);
    $billable = $billable->fresh();

    $captured = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$captured) {
        if ($request instanceof CreatePaymentRequest) {
            $captured = $request;

            return new class
            {
                public string $id = 'tr_free_mandate';

                public function getCheckoutUrl(): string
                {
                    return 'https://mollie.test/checkout/tr_free_mandate';
                }
            };
        }

        return (object) ['id' => 'cst_free'];
    });

    $result = app(StartSubscriptionCheckout::class)->handle($billable, [
        'plan_code' => 'free',
        'interval' => 'monthly',
        'amount_gross' => 0,
    ]);

    // If the service did send the customer to Mollie, play the webhook for that payment
    // so the assertion below is about the END state, not about an intermediate one.
    if ($captured !== null) {
        $metadata = (new ReflectionObject($captured))->getProperty('metadata');
        $metadata->setAccessible(true);

        app(MandateOnlyPaymentHandler::class)->paid(
            (object) [
                'id' => 'tr_free_mandate',
                'status' => 'paid',
                'customerId' => 'cst_free',
                'mandateId' => 'mdt_free',
                'countryCode' => 'AT',
                'amount' => (object) ['value' => '0.00', 'currency' => 'EUR'],
            ],
            $billable->fresh(),
            (array) $metadata->getValue($captured),
        );
    }

    $billable = $billable->fresh();

    expect($billable->hasAccessibleBillingSubscription())->toBeTrue()
        ->and($billable->subscription_plan_code)->toBe('free')
        ->and($billable->subscription_source)->toBe(SubscriptionSource::Local)
        ->and($billable->subscription_status)->toBe(SubscriptionStatus::Active)
        // A free plan needs no payment method.
        ->and($result['checkout_url'])->toBeNull()
        ->and($billable->hasMollieMandate())->toBeFalse()
        ->and($captured)->toBeNull()
        // The code could do nothing for this checkout, so it stops steering the session.
        ->and(Session::has('billing.auto_apply_coupon'))->toBeFalse();
});

it('keeps the session coupon while a paid checkout that uses it is merely pending', function (): void {
    app(CouponService::class)->create([
        'code' => 'WELCOME20',
        'name' => 'Welcome 20 %',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);
    Session::put('billing.auto_apply_coupon', 'WELCOME20');

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Paid Co', 'email' => 'paid@x.test', 'billing_country' => 'AT']);

    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => $request instanceof CreatePaymentRequest
        ? new class
        {
            public string $id = 'tr_paid';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_paid';
            }
        }
        : (object) ['id' => 'cst_paid']);

    // 30,00 − 20 % = 24,00 net, + 20 % AT VAT = 28,80: the amount reflects the coupon.
    app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 2880,
    ]);

    // The customer may still cancel on Mollie's page and come back for it.
    expect(Session::get('billing.auto_apply_coupon'))->toBe('WELCOME20');
});

it('drops a session coupon the submitted amount was not priced with', function (): void {
    app(CouponService::class)->create([
        'code' => 'WELCOME20',
        'name' => 'Welcome 20 %',
        'type' => CouponType::Recurring,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'max_redemptions_per_billable' => 3,
    ]);
    Session::put('billing.auto_apply_coupon', 'WELCOME20');

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Full Co', 'email' => 'full@x.test', 'billing_country' => 'AT']);

    Mollie::shouldReceive('send')->andReturnUsing(fn ($request) => $request instanceof CreatePaymentRequest
        ? new class
        {
            public string $id = 'tr_full';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_full';
            }
        }
        : (object) ['id' => 'cst_full']);

    app(StartSubscriptionCheckout::class)->handle($billable->fresh(), [
        'plan_code' => 'pro',
        'interval' => 'monthly',
        'amount_gross' => 3600,
    ]);

    expect(Session::has('billing.auto_apply_coupon'))->toBeFalse();
});
