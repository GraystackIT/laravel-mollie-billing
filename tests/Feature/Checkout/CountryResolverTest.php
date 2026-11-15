<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\CountryResolver;

it('resolves EU-27 countries by default', function (): void {
    $countries = CountryResolver::resolve();

    expect($countries)->toHaveCount(27);
    expect($countries)->toHaveKey('AT');
    expect($countries)->toHaveKey('DE');
    expect($countries)->toHaveKey('FR');
    expect($countries)->not->toHaveKey('CH');
    expect($countries)->not->toHaveKey('GB');
});

it('includes additional countries from config', function (): void {
    config()->set('mollie-billing.additional_countries', [
        'CH' => ['vat_rate' => 8.1, 'name' => 'Switzerland'],
    ]);

    $countries = CountryResolver::resolve();

    expect($countries)->toHaveKey('CH');
    expect($countries)->toHaveCount(28);
});

it('includes countries from checkout_countries.include', function (): void {
    config()->set('mollie-billing.checkout_countries', [
        'regions' => [],
        'include' => ['AT', 'DE'],
        'exclude' => [],
    ]);

    expect(array_keys(CountryResolver::resolve()))->toEqualCanonicalizing(['AT', 'DE']);
});

it('leaves out an included country that cannot be invoiced', function (): void {
    // This test used to assert the opposite — `include => ['GB']` put GB in the selector.
    // With no rate anywhere, the checkout priced it at 0 % and the paid payment could not
    // be booked. See UnpriceableCheckoutCountryTest.
    config()->set('mollie-billing.checkout_countries', [
        'regions' => ['EU'],
        'include' => ['GB'],
        'exclude' => [],
    ]);

    expect(CountryResolver::resolve())->not->toHaveKey('GB');

    config()->set('mollie-billing.additional_countries', ['GB' => ['vat_rate' => 20.0, 'name' => 'United Kingdom']]);

    expect(CountryResolver::resolve())->toHaveKey('GB');
});

it('excludes countries from checkout_countries.exclude', function (): void {
    config()->set('mollie-billing.checkout_countries', [
        'regions' => ['EU'],
        'include' => [],
        'exclude' => ['MT', 'CY'],
    ]);

    $countries = CountryResolver::resolve();

    expect($countries)->not->toHaveKey('MT');
    expect($countries)->not->toHaveKey('CY');
    expect($countries)->toHaveCount(25);
});

it('returns countries sorted by name', function (): void {
    $countries = CountryResolver::resolve();

    $names = array_values($countries);
    $sorted = $names;
    sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

    expect($names)->toBe($sorted);
});

it('provides raw EU country codes', function (): void {
    $codes = CountryResolver::euCountryCodes();

    expect($codes)->toHaveCount(27);
    expect($codes)->toContain('AT', 'DE', 'FR');
    expect($codes)->not->toContain('CH', 'GB');
});
