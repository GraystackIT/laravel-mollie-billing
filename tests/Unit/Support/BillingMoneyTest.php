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
