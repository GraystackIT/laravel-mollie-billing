<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\PastDueExpansionNotAllowedException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use GraystackIT\MollieBilling\Services\Billing\StartGrantExtrasPurchase;
use GraystackIT\MollieBilling\Services\Billing\StartSubscriptionCheckout;
use GraystackIT\MollieBilling\Services\Billing\UpgradeLocalToMollie;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/*
 * The screen-level `catch` for `error_past_due_booking`, where the screen keeps the message
 * in component state (seats: `flash`, checkout: `errorMessage`). The addons screen and the
 * products page report it as a Flux toast, which the feature suite cannot see — those two
 * are in the browser suite (`PastDueBookingRefusalTest`), together with these two.
 *
 * The booking service is made to refuse the way it does when the subscription turned past
 * due between the screen's own check and the booking; the refusal itself is tested at the
 * services (`OverageDebtPastDueTest`).
 */

beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 2,
            'feature_keys' => [], 'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 5000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', []);

    MollieBilling::authUsing(fn (): bool => true);

    // Any Mollie call means the refusal was not where it should be.
    Mollie::shouldReceive('send')->never();
});

it('the seats screen of a gifted tenant says the open payment comes first', function (string $locale): void {
    app()->setLocale($locale);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Gift Co', 'email' => uniqid('pd-gift-').'@x.test', 'billing_country' => 'AT']);
    $coupons = app(CouponService::class);
    $coupons->redeem($coupons->accessGrantCoupon('GRANT-PD'.strtoupper(bin2hex(random_bytes(3))), 'pro', 'monthly', [], 60), $billable->fresh(), []);
    $billable = $billable->fresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $purchase = Mockery::mock(StartGrantExtrasPurchase::class, [app(UpgradeLocalToMollie::class), app(SubscriptionCatalogInterface::class)])->makePartial();
    $purchase->shouldReceive('handle')->once()->andThrow(PastDueExpansionNotAllowedException::booking($billable));
    app()->instance(StartGrantExtrasPurchase::class, $purchase);

    $message = __('billing::portal.error_past_due_booking');
    expect($message)->not->toContain('billing::');

    Livewire::test('mollie-billing::seats')
        ->set('seatCount', 3)
        ->call('syncSeats')
        ->assertSet('flash', $message)
        ->assertSet('flashSuccess', false);

    expect($billable->fresh()->getBillingSeatCount())->toBe(2);
})->with(['en', 'de']);

it('the checkout says the open payment comes first, not that the payment failed', function (string $locale): void {
    app()->setLocale($locale);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Owes Ltd', 'email' => uniqid('pd-checkout-').'@x.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Local,
        'subscription_status' => SubscriptionStatus::Cancelled,
        'subscription_plan_code' => null,
        'subscription_interval' => SubscriptionInterval::Monthly,
        'billing_street' => 'Owes Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'tax_country_user' => 'AT',
    ])->save();
    $billable = $billable->refresh();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    $checkout = Mockery::mock(StartSubscriptionCheckout::class);
    $checkout->shouldReceive('handle')->once()->andThrow(PastDueExpansionNotAllowedException::booking($billable));
    app()->instance(StartSubscriptionCheckout::class, $checkout);

    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->call('submit');

    expect($component->get('errorMessage'))->toBe(__('billing::portal.error_past_due_booking'))
        ->and($component->get('errorMessage'))->not->toBe(__('billing::checkout.error_payment_creation'))
        ->and($billable->fresh()->getPendingFirstPaymentId())->toBeNull();
})->with(['en', 'de']);
