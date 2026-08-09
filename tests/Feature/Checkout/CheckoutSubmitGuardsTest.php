<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\CouponService;
use Livewire\Livewire;

// Two things `submit()` has to do regardless of which screen the customer walked through:
// run every custom step's validator (`$step` is a plain public property, so a crafted
// payload can jump past them), and price only the coupon types that actually move money.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    config()->set('mollie-billing-plans.plans', [
        'solo' => [
            'name' => 'Solo',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'trial_days' => 14, 'included_usages' => []]],
        ],
    ]);
});

function filledCheckout(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'Acme')
        ->set('billing_street', 'Teststr. 1')
        ->set('billing_postal_code', '1010')
        ->set('billing_city', 'Wien')
        ->set('billing_country', 'AT')
        ->set('plan_code', 'solo')
        ->set('interval', 'monthly');
}

it('runs every custom step validator on submit, even one the customer jumped past', function (): void {
    // The README tells apps to enforce things here that `createBillableUsing()` then
    // trusts. `next()` — where these normally run — is never called when `$step` is set
    // straight to the confirm step.
    MollieBilling::checkoutStepsUsing(fn (): array => [[
        'key' => 'terms',
        'label' => 'Terms',
        'headline' => 'Terms',
        'description' => 'Accept the terms',
        'view' => 'mollie-billing::livewire.billing.components.checkout.step-plan',
        'validate' => function ($component): void {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customData.terms' => 'Please accept the terms.',
            ]);
        },
    ]]);

    filledCheckout()
        // Straight to the last step, skipping the custom one entirely.
        ->set('step', 99)
        ->call('submit')
        ->assertHasErrors('customData.terms');
});

it('prices no discount for a coupon type that moves no money', function (): void {
    // The admin form keeps `discount_value` when the operator switches the type selector
    // after typing one, and `computeRecurringDiscount()` reads it regardless — so the
    // checkout quoted a reduced total for a coupon no webhook path honours, and the
    // customer was charged the full amount.
    app(CouponService::class)->create([
        'code' => 'TRIAL30',
        'name' => 'Trial 30',
        'type' => CouponType::TrialExtension,
        'trial_extension_days' => 30,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 50,
    ]);

    $component = filledCheckout()
        ->set('coupon_input', 'TRIAL30')
        ->call('applyCoupon');

    $applied = $component->get('applied_coupon');

    expect($component->get('couponError'))->toBeNull()
        ->and($applied)->not->toBeNull()
        ->and((int) ($applied['discount_net'] ?? -1))->toBe(0);
});
