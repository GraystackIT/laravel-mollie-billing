<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

/*
 * Every literal `billing::…` key the package's views and classes ask for exists in both
 * shipped locales.
 *
 * A missing key does not fail anywhere: `__()` answers with the key itself, and a page or
 * a flash then reads `billing::admin.scheduled_changes.applied_pending_payment` to the
 * operator. A browser test that waits for `__('billing::…')` waits for the same raw key
 * and passes. So this walks the source, collects every key written out in full, and asks
 * the translator for it in `de` and in `en` without falling back.
 *
 * Keys assembled at runtime (`'billing::portal.flash.'.$reason`) cannot be read from the
 * source and are left out: a literal that ends in `.` or `_` is such a prefix.
 */

/** @return array<string, list<string>> key => files that use it */
function translationKeysInSource(): array
{
    $root = dirname(__DIR__, 3);
    $keys = [];

    foreach (['resources/views', 'src'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all('/([\'"])(billing::[a-z_]+\.[A-Za-z0-9_.]+)\1/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[2] as $key) {
                if (str_ends_with($key, '.') || str_ends_with($key, '_')) {
                    continue;
                }

                $keys[$key][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    ksort($keys);

    return $keys;
}

it('finds the keys it is meant to check', function (): void {
    // A regex that silently matched nothing would make the next test pass vacuously.
    expect(count(translationKeysInSource()))->toBeGreaterThan(500);
});

it('has every literal billing:: key in both shipped locales', function (string $locale): void {
    $missing = [];

    foreach (translationKeysInSource() as $key => $files) {
        if (! Lang::hasForLocale($key, $locale)) {
            $missing[] = $key.'  ('.implode(', ', array_unique($files)).')';
        }
    }

    expect($missing)->toBe([], "Missing in {$locale}:\n".implode("\n", $missing));
})->with(['de', 'en']);
