<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use Livewire\Livewire;

// The addon/seat step is not rendered for a plan that sells neither, and `submit()` used
// to skip its validation for exactly that case. `addon_codes` and `extra_seats` then
// reached the order unchecked: a crafted Livewire payload bought free seats and switched
// on an addon reserved for another tier, whose features `FeatureAccess` grants from the
// active addon list.

beforeEach(function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    config()->set('mollie-billing-plans.plans', [
        // Sells no addons and no extra seats — the plan whose step is skipped.
        'solo' => [
            'name' => 'Solo',
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => [],
            'intervals' => ['monthly' => ['base_price_net' => 1000, 'seat_price_net' => null, 'included_usages' => []]],
        ],
        'team' => [
            'name' => 'Team',
            'tier' => 2,
            'included_seats' => 2,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => ['monthly' => ['base_price_net' => 2000, 'seat_price_net' => 500, 'included_usages' => []]],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        // Allowed by no plan at all.
        'enterprise-only' => [
            'name' => 'Enterprise Only',
            'feature_keys' => ['secret-feature'],
            'intervals' => ['monthly' => ['price_net' => 100]],
        ],
        'extra' => [
            'name' => 'Extra',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 500]],
        ],
    ]);
});

/** The checkout with a valid billing address, ready to submit. */
function checkoutReadyToSubmit(string $planCode): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('mollie-billing::checkout')
        ->set('company_name', 'Acme')
        ->set('billing_street', 'Teststr. 1')
        ->set('billing_postal_code', '1010')
        ->set('billing_city', 'Wien')
        ->set('billing_country', 'AT')
        ->set('plan_code', $planCode)
        ->set('interval', 'monthly');
}

it('refuses an addon no plan allows, even when the addon step is not rendered', function (): void {
    checkoutReadyToSubmit('solo')
        ->set('addon_codes', ['enterprise-only'])
        ->call('submit')
        ->assertHasErrors('addon_codes.0');
});

it('refuses extra seats on a plan that sells none', function (): void {
    checkoutReadyToSubmit('solo')
        ->set('extra_seats', 500)
        ->call('submit')
        ->assertHasErrors('extra_seats');
});

it('drops a seat count carried over to a selection that sells no seats', function (): void {
    // Pick Team, set seats, change your mind and pick Solo: the count used to survive and
    // be priced at 0 — free capacity, shown on the confirm step as if it were bought.
    $component = checkoutReadyToSubmit('team')
        ->set('extra_seats', 10);

    expect($component->get('extra_seats'))->toBe(10);

    $component->set('plan_code', 'solo');

    expect($component->get('extra_seats'))->toBe(0);
});

it('still accepts an addon the selected plan allows', function (): void {
    checkoutReadyToSubmit('team')
        ->set('addon_codes', ['extra'])
        ->set('extra_seats', 2)
        ->call('submit')
        ->assertHasNoErrors(['addon_codes.0', 'extra_seats']);
});
