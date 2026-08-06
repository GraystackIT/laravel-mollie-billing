<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use Livewire\Livewire;

/**
 * The checkout's grand total is the amount handed to Mollie as `amount_gross`, and the
 * webhook books that same payment line by line — `InvoiceService::createForPayment()`
 * derives the invoice header VAT by summing the per-line VAT. Rounding VAT once over the
 * summed net produces a different cent, so the customer was shown (and charged) an amount
 * the invoice then contradicted.
 */
beforeEach(function (): void {
    // 550 + 550 at 19%: per line 2 × 105 = 210, over the sum round(1100 × 0.19) = 209.
    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'included_seats' => 1,
            'feature_keys' => [],
            'allowed_addons' => ['extra'],
            'intervals' => [
                'monthly' => ['base_price_net' => 550, 'seat_price_net' => null, 'included_usages' => []],
                'yearly' => ['base_price_net' => 5500, 'seat_price_net' => null, 'included_usages' => []],
            ],
        ],
    ]);
    config()->set('mollie-billing-plans.addons', [
        'extra' => [
            'name' => 'Extra',
            'feature_keys' => [],
            'intervals' => ['monthly' => ['price_net' => 550], 'yearly' => ['price_net' => 5500]],
        ],
    ]);

    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn (): bool => true);
});

it('assembles the checkout total from per-line VAT, not from the summed net', function (): void {
    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'DE')
        ->set('plan_code', 'pro')
        ->set('addon_codes', ['extra']);

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($totals['net'])->toBe(1100)
        ->and($totals['vat'])->toBe(210)
        ->and($totals['gross'])->toBe(1310);
});

it('renders the per-line total on the confirm step', function (): void {
    Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'DE')
        ->set('plan_code', 'pro')
        ->set('addon_codes', ['extra'])
        ->set('step', 4)
        ->assertSee('€13.10')
        ->assertDontSee('€13.09');
});

it('keeps the coupon discount on its own line when assembling the total', function (): void {
    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'DE')
        ->set('plan_code', 'pro')
        ->set('addon_codes', ['extra']);

    // A 550 discount is booked as a negative line, so the VAT residuals of the three
    // lines are what the payment collects: 105 + 105 − 105.
    $component->instance()->applied_coupon = ['code' => 'HALF', 'name' => 'Half', 'discount_net' => 550];

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($component->instance()->lineNets())->toBe([550, 550, -550])
        ->and($totals['net'])->toBe(550)
        ->and($totals['gross'])->toBe(655);
});

it('caps the coupon line at the order total so the assembled gross never goes negative', function (): void {
    $component = Livewire::test('mollie-billing::checkout')
        ->set('billing_country', 'DE')
        ->set('plan_code', 'pro');

    $component->instance()->applied_coupon = ['code' => 'FREE', 'name' => 'Free', 'discount_net' => 5000];

    $totals = $component->instance()->totals(app(VatCalculationService::class));

    expect($component->instance()->lineNets())->toBe([550, -550])
        ->and($totals['net'])->toBe(0)
        ->and($totals['gross'])->toBe(0);
});
