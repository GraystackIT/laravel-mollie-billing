<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Laravel\Facades\Mollie;

/*
 * The portal for a tenant who was GIVEN their plan. The addons and the seats screen used
 * to answer them with "not available — change your plan", and the plan page's only offer
 * was to give up the gift. They now start the purchase directly.
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'free' => [
            'name' => 'Free', 'tier' => 0, 'included_seats' => 1,
            'feature_keys' => [], 'allowed_addons' => ['sso', 'badge'],
            'intervals' => ['monthly' => ['base_price_net' => 0, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => ['sso', 'badge'],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'sso' => ['name' => 'SSO', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 1500]]],
        'badge' => ['name' => 'Badge', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 0]]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);

    $resolver = Mockery::mock(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cst_gift_screen');
    app()->instance(\GraystackIT\MollieBilling\Support\MollieCustomerResolver::class, $resolver);
});

function giftedScreenTenant(): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gift Screen Co', 'email' => uniqid('gift-screen-').'@x.test', 'billing_country' => 'AT']);

    $service = app(CouponService::class);
    $code = 'GRANT-'.strtoupper(bin2hex(random_bytes(3)));
    $service->redeem($service->accessGrantCoupon($code, 'pro', 'monthly', [], 60), $billable->fresh(), []);

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    return $billable;
}

/** @return array<int, object> the requests sent to Mollie */
function captureGiftPurchase(string $checkoutUrl = 'https://mollie.test/checkout/gift'): ArrayObject
{
    $sent = new ArrayObject;

    Mollie::shouldReceive('send')->andReturnUsing(function ($request) use ($sent, $checkoutUrl) {
        $sent[] = $request;

        return new class($checkoutUrl)
        {
            public string $id = 'tr_gift_screen';

            public function __construct(private readonly string $url)
            {
            }

            public function getCheckoutUrl(): string
            {
                return $this->url;
            }
        };
    });

    return $sent;
}

it('tells a gifted tenant on the addons screen that extras can be bought', function (): void {
    $billable = giftedScreenTenant();

    Livewire::test('mollie-billing::addons')
        ->assertSee(__('billing::portal.access_grant_extras_available', [
            'date' => \GraystackIT\MollieBilling\Support\BillingTime::display($billable->getBillingSubscriptionEndsAt(), $billable)->translatedFormat('d. M Y'),
        ]))
        ->assertDontSee(__('billing::portal.access_grant_no_paid_extras'));
});

it('sends a gifted tenant to Mollie for a paid addon, charging the addon only', function (): void {
    $billable = giftedScreenTenant();
    $sent = captureGiftPurchase();

    Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'sso')
        ->assertRedirect('https://mollie.test/checkout/gift');

    $payment = collect($sent)->first(fn ($r) => $r instanceof CreatePaymentRequest);
    $amount = (new ReflectionClass($payment))->getProperty('amount');
    $amount->setAccessible(true);

    // SSO 15.00 + 20 % — not the 50.00 plan.
    expect($amount->getValue($payment)->value)->toBe('18.00');

    // Nothing changed locally yet: the addon arrives with the payment.
    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe([])
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBe('tr_gift_screen');
});

it('enables a free addon for a gifted tenant without going to Mollie', function (): void {
    $billable = giftedScreenTenant();
    Mollie::shouldReceive('send')->never();

    Livewire::test('mollie-billing::addons')
        ->call('enableAddon', 'badge')
        ->assertNoRedirect();

    expect($billable->fresh()->getActiveBillingAddonCodes())->toBe(['badge']);
});

it('sends a gifted tenant to Mollie for extra seats, charging the extra seats only', function (): void {
    $billable = giftedScreenTenant();
    $sent = captureGiftPurchase();

    Livewire::test('mollie-billing::seats')
        ->set('seatCount', 4)
        ->call('syncSeats')
        ->assertRedirect('https://mollie.test/checkout/gift');

    $payment = collect($sent)->first(fn ($r) => $r instanceof CreatePaymentRequest);
    $amount = (new ReflectionClass($payment))->getProperty('amount');
    $amount->setAccessible(true);

    // Two extra seats at 5.00 + 20 %.
    expect($amount->getValue($payment)->value)->toBe('12.00');
});

it('still tells a tenant on an ordinary free plan to change plan', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Free Co', 'email' => uniqid('free-').'@x.test', 'billing_country' => 'AT']);
    app(\GraystackIT\MollieBilling\Services\Billing\ActivateLocalSubscription::class)->handle($billable, 'free', 'monthly');
    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    expect(app(StartGrantExtrasPurchase::class)->eligible($billable))->toBeFalse();

    Livewire::test('mollie-billing::addons')
        ->assertSee(__('billing::portal.free_plan_no_paid_extras'));
});

it('tells a tenant on the dashboard until when the plan is free and what happens then', function (string $onExpiry): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Waived Co', 'email' => uniqid('waived-').'@x.test', 'billing_country' => 'AT']);
    $billable->forceFill([
        'subscription_source' => \GraystackIT\MollieBilling\Enums\SubscriptionSource::Mollie,
        'subscription_status' => \GraystackIT\MollieBilling\Enums\SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => \GraystackIT\MollieBilling\Enums\SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(3),
        'active_addon_codes' => ['sso'],
        'subscription_meta' => ['mollie_subscription_id' => 'sub_waived', 'seat_count' => 2],
        'mollie_customer_id' => 'cst_waived',
        'mollie_mandate_id' => 'mdt_waived',
    ])->save();

    $coupon = app(CouponService::class)->accessGrantCoupon('GRANT-'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], 40);
    $until = \GraystackIT\MollieBilling\Support\BillingTime::nowUtc()->addDays(40);
    app(\GraystackIT\MollieBilling\Services\Billing\PlanWaiver::class)
        ->grant($billable->fresh(), $coupon, 'pro', 'monthly', [], $until, null, $onExpiry);

    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Livewire::test('mollie-billing::dashboard')
        ->assertSee(__('billing::portal.plan_waiver_notice_'.$onExpiry, [
            'date' => \GraystackIT\MollieBilling\Support\BillingTime::display($until, $billable)->translatedFormat('d. M Y'),
        ]));
})->with(['end', 'continue_paid']);
