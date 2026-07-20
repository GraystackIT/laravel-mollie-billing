<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Support\BillingAuditMap;

// The whole design rests on the stored key resolving to real text at render time.
// A new event added to the map without a translation would silently render as
// "Unrecognised billing event" in production — these tests are the guard.

dataset('locales', ['en', 'de']);

it('has a translation for every audited event', function (string $locale): void {
    app()->setLocale($locale);

    $missing = [];

    foreach (BillingAuditMap::all() as $eventClass => $descriptor) {
        $key = 'billing::'.$descriptor->descriptionKey();

        if (trans($key) === $key) {
            $missing[] = $descriptor->key.' ('.class_basename($eventClass).')';
        }
    }

    expect($missing)->toBe([], "Missing [{$locale}] audit translations: ".implode(', ', $missing));
})->with('locales');

it('has a translation for every audit category', function (string $locale): void {
    app()->setLocale($locale);

    foreach (AuditCategory::cases() as $category) {
        $key = 'billing::audit.category.'.$category->value;
        expect(trans($key))->not->toBe($key, "Missing [{$locale}] label for category {$category->value}");
    }
})->with('locales');

it('has a translation for every actor kind', function (string $locale): void {
    app()->setLocale($locale);

    foreach (['system', 'admin', 'customer'] as $actor) {
        $key = 'billing::audit.actor.'.$actor;
        expect(trans($key))->not->toBe($key, "Missing [{$locale}] label for actor {$actor}");
    }
})->with('locales');

it('covers every event class shipped in src/Events', function (): void {
    $eventFiles = glob(__DIR__.'/../../../src/Events/*.php') ?: [];

    $shipped = array_map(
        fn (string $path): string => 'GraystackIT\\MollieBilling\\Events\\'.basename($path, '.php'),
        $eventFiles,
    );

    $audited = array_keys(BillingAuditMap::all());
    $unaudited = array_values(array_diff($shipped, $audited));

    expect($unaudited)->toBe([], 'Events missing from BillingAuditMap: '.implode(', ', $unaudited));
});

it('stores description keys that spatie will not mangle', function (): void {
    // Spatie replaces /:[a-z0-9._-]+(?<![.])/i in the description before saving.
    foreach (BillingAuditMap::all() as $descriptor) {
        expect($descriptor->descriptionKey())->not->toContain(':');
    }
});
