<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Services\Vat\VatCalculationService;
use GraystackIT\MollieBilling\Testing\TestBillable;

// `vat_rate_overrides` has the highest precedence in VatCalculationService::vatRateFor()
// and is cast with `(float)` — no validation anywhere. `billing:check-config` does not look
// at the key at all, and for `additional_countries.*.vat_rate` it only asks `is_numeric()`.
//
// The rate is a percentage (19.0). mpociot — the library the same service falls back to —
// speaks decimals (0.19), so the fraction is the mistake waiting to happen: it is numeric,
// it passes, and every invoice for that country carries 0.19 % VAT.

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
});

it('errors on a vat_rate_overrides entry that cannot be a percentage', function (mixed $bad): void {
    config()->set('mollie-billing.vat_rate_overrides', ['DE' => $bad]);

    // What the misconfiguration does at runtime, for the record: 100,00 € net in Germany.
    $charged = app(VatCalculationService::class)->calculate('DE', 10000);
    expect($charged['vat'])->toBeLessThan(1900);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('vat_rate_overrides')
        ->assertExitCode(1);
})->with([
    'decimal fraction instead of percent' => [0.19],
    'not a number' => ['neunzehn'],
    'negative' => [-19],
]);

it('errors on an additional_countries rate written as a decimal fraction', function (): void {
    config()->set('mollie-billing.additional_countries', ['CH' => ['vat_rate' => 0.081, 'name' => 'Switzerland']]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('additional_countries.CH.vat_rate')
        ->assertExitCode(1);
});

it('accepts the rates that are percentages, including 0', function (mixed $good): void {
    config()->set('mollie-billing.vat_rate_overrides', ['DE' => $good]);
    config()->set('mollie-billing.additional_countries', ['CH' => ['vat_rate' => $good, 'name' => 'Switzerland']]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('vat_rate')
        ->assertExitCode(0);
})->with(['zero' => [0], 'reduced' => [7.0], 'numeric string' => ['19'], 'one' => [1], 'hundred' => [100]]);

it('errors on a rate above 100 and on a lowercase override key', function (): void {
    config()->set('mollie-billing.vat_rate_overrides', ['de' => 19.0, 'FR' => 120]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('vat_rate_overrides.FR')
        ->assertExitCode(1);
});

// `checkout_countries.include` only feeds the selector; the VAT rate comes from elsewhere.
it('errors on an included checkout country that has no VAT rate', function (): void {
    config()->set('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => ['GB'], 'exclude' => []]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('checkout_countries.include lists [GB]')
        ->assertExitCode(1);
});

it('accepts an included country that is declared with its rate, and an EU one', function (): void {
    config()->set('mollie-billing.checkout_countries', ['regions' => [], 'include' => ['AT', 'GB'], 'exclude' => []]);
    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('checkout_countries.include')
        ->assertExitCode(0);
});
