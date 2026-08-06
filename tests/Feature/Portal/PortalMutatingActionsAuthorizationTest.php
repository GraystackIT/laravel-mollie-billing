<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;

/**
 * Livewire actions POST to Livewire's own endpoint, so the portal route middleware
 * never sees them. Every dashboard and billing-data action that mutates the billable
 * therefore has to call MollieBilling::authorizes() itself — otherwise a member the
 * host app denies billing management to can still cancel the tenant's subscription or
 * rewrite the company's VAT number.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans.pro', [
        'name' => 'Pro',
        'tier' => 2,
        'included_seats' => 5,
        'feature_keys' => [],
        'allowed_addons' => [],
        'intervals' => [
            'monthly' => ['base_price_net' => 1000, 'seat_price_net' => 500, 'included_usages' => []],
            'yearly' => ['base_price_net' => 10000, 'seat_price_net' => 5000, 'included_usages' => []],
        ],
    ]);

    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);
    $billable->forceFill([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => now()->subDays(5),
        'trial_ends_at' => now()->addDays(5),
        'billing_street' => 'Acme Street 1',
        'billing_postal_code' => '1010',
        'billing_city' => 'Vienna',
        'billing_country' => 'AT',
        'mollie_customer_id' => 'cst_test_123',
        'mollie_mandate_id' => 'mdt_test_123',
        'subscription_meta' => ['seat_count' => 5, 'mollie_subscription_id' => 'sub_test_123'],
    ])->save();

    $billable = $billable->refresh();
    $this->billable = $billable;

    // Captured by value: the resolver callback is static and outlives this file, so it
    // must never query — RefreshDatabase has dropped the row by the time it fires again.
    MollieBilling::resolveBillableUsing(fn () => $billable);
    // Deny by default — every mutating portal action must enforce authorization.
    MollieBilling::authUsing(fn (): bool => false);
});

dataset('denied dashboard actions', [
    'openCountryFix' => ['openCountryFix', []],
    'saveCountryFix' => ['saveCountryFix', []],
    'reactivateAfterMismatch' => ['reactivateAfterMismatch', []],
    'cancelSubscription' => ['cancelSubscription', []],
    'endTrial' => ['endTrial', []],
    'resubscribe' => ['resubscribe', []],
    'forceImmediateMollieCharge' => ['forceImmediateMollieCharge', []],
    'cancelScheduledChange' => ['cancelScheduledChange', []],
]);

it('forbids dashboard action when authorization is denied', function (string $action, array $args): void {
    Livewire::test('mollie-billing::dashboard')
        ->call($action, ...$args)
        ->assertForbidden();
})->with('denied dashboard actions');

it('forbids redeeming a coupon from the dashboard when authorization is denied', function (): void {
    Livewire::test('mollie-billing::dashboard')
        ->set('couponCode', 'WELCOME10')
        ->call('redeemCoupon')
        ->assertForbidden();
});

it('does not cancel the subscription when authorization is denied', function (): void {
    Livewire::test('mollie-billing::dashboard')
        ->call('cancelSubscription')
        ->assertForbidden();

    expect($this->billable->refresh()->getBillingSubscriptionStatus())
        ->toBe(SubscriptionStatus::Trial);
});

it('forbids saving billing data when authorization is denied', function (): void {
    Livewire::test('mollie-billing::billing-data')
        ->set('company_name', 'Attacker GmbH')
        ->set('billing_street', 'Attacker Street 9')
        ->set('billing_postal_code', '9999')
        ->set('billing_city', 'Nowhere')
        ->set('billing_country', 'DE')
        ->call('save')
        ->assertForbidden();

    // The VAT-relevant fields are untouched.
    expect($this->billable->refresh()->getBillingCountry())->toBe('AT')
        ->and($this->billable->getBillingStreet())->toBe('Acme Street 1');
});

it('forbids starting a payment-method change when authorization is denied', function (): void {
    Livewire::test('mollie-billing::billing-data')
        ->call('changePaymentMethod')
        ->assertForbidden();
});

it('allows the dashboard actions again once the host app grants authorization', function (): void {
    MollieBilling::authUsing(fn (): bool => true);

    Livewire::test('mollie-billing::dashboard')
        ->call('cancelSubscription')
        ->assertOk();

    expect($this->billable->refresh()->getBillingSubscriptionStatus())
        ->not->toBe(SubscriptionStatus::Trial);
});

it('forbids resolving a country mismatch when authorization is denied', function (): void {
    BillingCountryMismatch::create([
        'billable_type' => $this->billable->getMorphClass(),
        'billable_id' => $this->billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'DE',
        'status' => CountryMismatchStatus::Pending,
    ]);

    Livewire::test('mollie-billing::dashboard')
        ->set('fixCountry', 'DE')
        ->set('fixStreet', 'Somewhere 1')
        ->set('fixPostalCode', '10115')
        ->set('fixCity', 'Berlin')
        ->set('fixConfirmed', true)
        ->call('saveCountryFix')
        ->assertForbidden();

    expect($this->billable->refresh()->getBillingCountry())->toBe('AT');
});
