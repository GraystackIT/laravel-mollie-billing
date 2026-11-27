<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * A promotion link whose code is restricted to a plan works on a checkout with several.
 *
 * `PromotionController` sends the customer to the checkout without a plan. The code was
 * tried once, at mount — with no plan chosen and a subtotal of 0 — so a code restricted
 * to a plan, to an addon or to a minimum order was refused there, silently, and never
 * tried again: picking exactly the plan the campaign was for showed the full price, and
 * at submit `StartSubscriptionCheckout` dropped the code (the amount did not reflect it)
 * and took it out of the session. The customer paid full price and was never told.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'starter' => [
            'name' => 'Starter', 'tier' => 1, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 3000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
    ]);

    MollieBilling::authUsing(fn (): bool => true);

    app(CouponService::class)->create([
        'code' => 'PROONLY',
        'name' => 'Pro launch',
        'type' => CouponType::SinglePayment,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 20,
        'applicable_plans' => ['pro'],
        'auto_apply_token' => 'tok-pro-only',
    ]);
});

function promotionCheckoutBillable(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Campaign Ltd', 'email' => uniqid('campaign-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Campaign Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
        'mollie_customer_id' => 'cst_campaign',
    ])->save();

    return $billable->refresh();
}

it('applies a plan-restricted promotion code once the customer picks that plan, and charges the discounted price', function (): void {
    $billable = promotionCheckoutBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);
    session([StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY => 'PROONLY']);

    $created = null;
    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use (&$created) {
        if ($request instanceof CreatePaymentRequest) {
            $created = $request;
        }

        return new class
        {
            public string $id = 'tr_campaign';

            public string $status = 'open';

            public function getCheckoutUrl(): string
            {
                return 'https://mollie.test/checkout/tr_campaign';
            }
        };
    });

    // Mounted without `?plan` — as the promotion link redirects.
    $component = Livewire::test('mollie-billing::checkout');

    expect($component->get('applied_coupon'))->toBeNull();

    $component->set('plan_code', 'pro');

    expect($component->get('applied_coupon')['code'] ?? null)->toBe('PROONLY');

    $component->call('submit');

    $metadata = (new ReflectionObject($created))->getProperty('metadata')->getValue($created);
    $amount = (new ReflectionObject($created))->getProperty('amount')->getValue($created);

    // 30.00 − 20 % = 24.00 net, + 20 % AT VAT = 28.80.
    expect($metadata['coupon_code'])->toBe('PROONLY')
        ->and($amount->value)->toBe('28.80');
});

it('does not put a promotion code back that the customer removed', function (): void {
    $billable = promotionCheckoutBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);
    session([StartSubscriptionCheckout::AUTO_APPLY_COUPON_SESSION_KEY => 'PROONLY']);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro');

    expect($component->get('applied_coupon')['code'] ?? null)->toBe('PROONLY');

    $component->call('removeCoupon')
        ->set('plan_code', 'starter')
        ->set('plan_code', 'pro');

    expect($component->get('applied_coupon'))->toBeNull();
});
