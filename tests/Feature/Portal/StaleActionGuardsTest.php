<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Exceptions\SeatCountExceedsMaximumException;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Billing\SyncSeats;
use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Livewire\Livewire;
use Mollie\Laravel\Facades\Mollie;

/**
 * A rendered button is a statement about a moment that has already passed.
 *
 * Livewire actions are separate requests, so every one of them has to re-ask the question
 * its own visibility was gated on — and clamp what the client sends, since a number input's
 * `min`/`max` live in the browser.
 */
beforeEach(function (): void {
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['analytics'],
            'intervals' => [
                'monthly' => ['base_price_net' => 2900, 'seat_price_net' => 500, 'included_usages' => []],
                'yearly' => ['base_price_net' => 29000, 'seat_price_net' => 5000, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        // Monthly only — switching to yearly must not carry it along at price 0.
        'analytics' => ['name' => 'Analytics', 'feature_keys' => [], 'intervals' => ['monthly' => ['price_net' => 500]]],
    ]);

    MollieBilling::authUsing(fn (): bool => true);
});

function staleActionBillable(array $overrides = []): TestBillable
{
    /** @var TestBillable $billable */
    $billable = TestBillable::create([
        'name' => 'Stale Ltd',
        'email' => uniqid('stale-').'@x.test',
        'billing_country' => 'AT',
    ]);

    $billable->forceFill(array_merge([
        'subscription_source' => SubscriptionSource::Mollie,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_plan_code' => 'pro',
        'subscription_interval' => SubscriptionInterval::Monthly,
        'subscription_period_starts_at' => BillingTime::nowUtc()->subDays(4),
        'mollie_customer_id' => 'cst_stale',
        'mollie_mandate_id' => 'mdt_stale',
        'subscription_meta' => ['mollie_subscription_id' => 'sub_stale', 'seat_count' => 1],
    ], $overrides))->save();

    return $billable->refresh();
}

it('refuses to force a charge on a subscription that is no longer past due', function (): void {
    // The banner renders for PastDue plus a future Mollie start date, and the dashboard has
    // no wire:poll. Between the render and the click Mollie can charge and the webhook
    // activate the subscription — and forceImmediateCharge() looks at neither status nor
    // start date, so the click PATCHed startDate to today on an Active subscription and
    // collected a second full period for one already paid.
    $billable = staleActionBillable();
    MollieBilling::resolveBillableUsing(fn () => $billable);

    Mollie::shouldReceive('send')->never();

    $component = Livewire::test('mollie-billing::dashboard')->call('forceImmediateMollieCharge');

    expect($component->get('flashError'))->toBeTrue()
        ->and($component->get('flash'))->toBe(__('billing::portal.flash.force_charge_no_longer_needed'));
});

it('refuses a seat count beyond the configured maximum', function (): void {
    config()->set('mollie-billing.max_seats', 50);

    $billable = staleActionBillable();

    // `$wire.set('seatCount', 2000000)` or a fat-fingered paste: the input's `min` is
    // client-side only and the service clamped nothing but the lower bound, so
    // SubscriptionAmount::net() raised a prorata charge in the millions.
    Mollie::shouldReceive('send')->never();

    expect(fn () => app(SyncSeats::class)->handle($billable, 2_000_000))
        ->toThrow(SeatCountExceedsMaximumException::class);

    expect($billable->fresh()->getBillingSeatCount())->toBe(1);
});

it('drops an addon the newly selected interval does not sell', function (): void {
    MollieBilling::resolveBillableUsing(fn () => null);

    // Priced at 0 for yearly, a monthly-only addon rendered as "€0.00 / per year", looked
    // like a free perk of the annual plan, and was only refused when the order reached
    // StartSubscriptionCheckout — where submit() could report nothing more useful than
    // "payment could not be created".
    $component = Livewire::test('mollie-billing::checkout')
        ->set('plan_code', 'pro')
        ->set('interval', 'monthly')
        ->set('addon_codes', ['analytics']);

    expect($component->get('addon_codes'))->toBe(['analytics']);

    $component->set('interval', 'yearly');

    expect($component->get('addon_codes'))->toBe([])
        ->and($component->instance()->plans()['pro']['allowed_addons'])->toBe([]);
});
