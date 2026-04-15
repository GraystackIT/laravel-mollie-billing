<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;

beforeEach(function (): void {
    config()->set('mollie-billing-plans.features', [
        'config_only' => ['name' => 'Config Only', 'description' => 'Main dashboard'],
        'no_desc' => ['name' => 'Only Name', 'description' => null],
        'unregistered_but_translated' => [],
    ]);
});

it('returns translated name when translation exists', function (): void {
    app('translator')->addLines(
        ['features.config_only.name' => 'Übersetzter Name'],
        'de',
        'billing'
    );
    app()->setLocale('de');

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureName('config_only'))->toBe('Übersetzter Name');
});

it('falls back to config name when no translation', function (): void {
    app()->setLocale('en');

    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureName('no_desc'))->toBe('Only Name');
});

it('falls back to headline of key when neither translation nor config', function (): void {
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureName('some-unknown_key'))->toBe('Some Unknown Key');
});

it('returns description from config when present', function (): void {
    app()->setLocale('en');
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureDescription('config_only'))->toBe('Main dashboard');
});

it('returns null description when not set', function (): void {
    app()->setLocale('en');
    $catalog = app(SubscriptionCatalogInterface::class);

    expect($catalog->featureDescription('no_desc'))->toBeNull();
    expect($catalog->featureDescription('totally-unknown'))->toBeNull();
});

it('allFeatures returns registered keys with display data', function (): void {
    app()->setLocale('en');
    $catalog = app(SubscriptionCatalogInterface::class);

    $all = $catalog->allFeatures();

    expect($all)->toHaveKeys(['config_only', 'no_desc', 'unregistered_but_translated']);
    expect($all['config_only']['name'])->toBe('Config Only');
    expect($all['config_only']['description'])->toBe('Main dashboard');
    expect($all['no_desc']['description'])->toBeNull();
});
