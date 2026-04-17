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
        'regions' => ['EU'],
        'include' => ['GB'],
        'exclude' => [],
    ]);

    $countries = CountryResolver::resolve();

    expect($countries)->toHaveKey('GB');
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
