<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingMoney;

it('formats cents with the configured currency symbol', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    expect(BillingMoney::format(1234))->toBe('€12.34')
        ->and(BillingMoney::format(0))->toBe('€0.00')
        ->and(BillingMoney::format(null))->toBe('€0.00');
});

it('uses a non-EUR configured symbol', function (): void {
    config()->set('mollie-billing.currency', 'CHF');
    config()->set('mollie-billing.currency_symbol', 'CHF ');

    expect(BillingMoney::format(50000))->toBe('CHF 500.00');
});

it('falls back to a known symbol when the configured one is empty', function (): void {
    config()->set('mollie-billing.currency', 'USD');
    config()->set('mollie-billing.currency_symbol', '');

    expect(BillingMoney::format(999))->toBe('$9.99');
});

it('honors an explicitly passed currency over the configured one', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    expect(BillingMoney::format(1000, 'GBP'))->toBe('£10.00');
});

it('prefixes a plus sign only for positive signed amounts', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    expect(BillingMoney::format(500, signed: true))->toBe('+€5.00')
        ->and(BillingMoney::format(0, signed: true))->toBe('€0.00');
});

it('puts the minus sign in front of the currency symbol', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    expect(BillingMoney::format(-500))->toBe('-€5.00')
        ->and(BillingMoney::format(-500, signed: true))->toBe('-€5.00')
        ->and(BillingMoney::format(-123456))->toBe('-€1,234.56');
});

it('derives the symbol from the currency when no symbol is configured', function (): void {
    config()->set('mollie-billing.currency_symbol', null);

    config()->set('mollie-billing.currency', 'GBP');
    expect(BillingMoney::format(1000))->toBe('£10.00');

    config()->set('mollie-billing.currency', 'USD');
    expect(BillingMoney::format(1000))->toBe('$10.00');

    config()->set('mollie-billing.currency', 'CHF');
    expect(BillingMoney::format(1000))->toBe('CHF 10.00');
});

it('groups digits according to the active locale', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    if (! class_exists(\NumberFormatter::class)) {
        expect(BillingMoney::format(123456789))->toBe('€1,234,567.89');

        return;
    }

    app()->setLocale('en');
    expect(BillingMoney::format(123456789))->toBe('€1,234,567.89');

    app()->setLocale('de');
    expect(BillingMoney::format(123456789))->toBe('€1.234.567,89');
});

// ── Minor-unit scale ──────────────────────────────────────────────────────────

it('reads the minor-unit scale off the currency', function (): void {
    expect(BillingMoney::fractionDigits('EUR'))->toBe(2)
        ->and(BillingMoney::fractionDigits('JPY'))->toBe(0)
        ->and(BillingMoney::fractionDigits('KWD'))->toBe(3)
        ->and(BillingMoney::minorUnitFactor('EUR'))->toBe(100)
        ->and(BillingMoney::minorUnitFactor('JPY'))->toBe(1)
        ->and(BillingMoney::minorUnitFactor('KWD'))->toBe(1000);
});

it('treats an unknown currency code as two decimals instead of throwing', function (): void {
    expect(BillingMoney::fractionDigits('XX'))->toBe(2)
        ->and(BillingMoney::minorUnitFactor('XX'))->toBe(100)
        ->and(BillingMoney::mollieAmount(2900, 'XX'))->toBe('29.00');
});

it('spells an outbound Mollie amount with the currency’s own decimals', function (): void {
    // The defect: 2900 minor units of JPY are ¥2900, not ¥29.00 — a hardcoded
    // number_format(…, 2) charged a hundredth of the intended amount.
    expect(BillingMoney::mollieAmount(2900, 'EUR'))->toBe('29.00')
        ->and(BillingMoney::mollieAmount(2900, 'JPY'))->toBe('2900')
        ->and(BillingMoney::mollieAmount(2900, 'jpy'))->toBe('2900')
        ->and(BillingMoney::mollieAmount(0, 'EUR'))->toBe('0.00')
        ->and(BillingMoney::mollieAmount(0, 'JPY'))->toBe('0')
        ->and(BillingMoney::mollieAmount(2900, 'KWD'))->toBe('2.900');
});

it('falls back to the configured currency for an outbound amount', function (): void {
    config()->set('mollie-billing.currency', 'JPY');

    expect(BillingMoney::mollieAmount(2900))->toBe('2900');
});

it('renders a 0-decimal currency without inventing a fractional part', function (): void {
    config()->set('mollie-billing.currency', 'JPY');
    config()->set('mollie-billing.currency_symbol', null);
    app()->setLocale('en');

    // Used to render "JPY 29.00" for a ¥2900 plan.
    expect(BillingMoney::format(2900))->toBe('JPY 2,900')
        ->and(BillingMoney::numberFromMinor(2900))->toBe('2,900')
        ->and(BillingMoney::format(-2900))->toBe('-JPY 2,900');
});

// ── VAT per line vs VAT on the total ──────────────────────────────────────────

it('sums VAT per line so the charge matches the invoice header', function (): void {
    // 550 + 550 at 19%: per line 105 + 105 = 210 → 1310. VAT on the summed 1100
    // is 209 → 1309, which is what the invoice would NOT book.
    expect(BillingMoney::grossWithLineVat([550, 550], 19.0))->toBe(1310)
        ->and(BillingMoney::grossWithLineVat([1100], 19.0))->toBe(1309)
        // plan 1999 + two 999 addons: 380 + 190 + 190 = 760 → 4757 (vs 4756).
        ->and(BillingMoney::grossWithLineVat([1999, 999, 999], 19.0))->toBe(4757)
        ->and(BillingMoney::grossWithLineVat([3997], 19.0))->toBe(4756);
});

it('rounds a negative discount line on its own', function (): void {
    // 1000 charged, 550 discounted: 1190 - 655 = 535. Rounding the netted 450
    // gives 536 — one cent the customer would pay but no line accounts for.
    expect(BillingMoney::grossWithLineVat([1000, -550], 19.0))->toBe(535)
        ->and(BillingMoney::grossWithLineVat([450], 19.0))->toBe(536);
});

it('collapses to the plain sum at a zero rate', function (): void {
    expect(BillingMoney::grossWithLineVat([550, 550], 0.0))->toBe(1100)
        ->and(BillingMoney::grossWithLineVat([], 19.0))->toBe(0);
});

it('prices one unit of a pack with two decimals more than the currency', function (): void {
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.currency_symbol', '€');

    app()->setLocale('en');
    // 1000 SMS for €99.13: a unit costs less than the smallest coin.
    expect(BillingMoney::formatUnitPrice(9913, 1000))->toBe('€0.0991')
        ->and(BillingMoney::formatUnitPrice(8330, 1000))->toBe('€0.0833')
        // A pack worth more than a unit per coin keeps its four decimals too.
        ->and(BillingMoney::formatUnitPrice(123456, 10))->toBe('€123.4560')
        // A non-positive quantity divides by one instead of by zero.
        ->and(BillingMoney::formatUnitPrice(500, 0))->toBe('€5.0000');

    app()->setLocale('de');
    expect(BillingMoney::formatUnitPrice(9913, 1000))->toBe('€0,0991')
        ->and(BillingMoney::formatUnitPrice(123456, 10))->toBe('€123,4560');

    app()->setLocale('en');
});

it('adds two decimals to the currency’s own, not to two', function (): void {
    config()->set('mollie-billing.currency', 'JPY');
    config()->set('mollie-billing.currency_symbol', '');
    app()->setLocale('en');

    // ¥500 for 3 units: JPY has no decimals, so the unit price gets two.
    expect(BillingMoney::formatUnitPrice(500, 3))->toBe('JPY 166.67');
});

it('writes a whole count in the active locale', function (): void {
    app()->setLocale('en');
    expect(BillingMoney::count(1000))->toBe('1,000')
        ->and(BillingMoney::count(500))->toBe('500');

    app()->setLocale('de');
    expect(BillingMoney::count(1000))->toBe('1.000')
        ->and(BillingMoney::count(1250000))->toBe('1.250.000');

    app()->setLocale('en');
});
