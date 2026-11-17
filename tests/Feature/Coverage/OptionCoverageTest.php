<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\Support\OptionUniverse;

/*
 * "Every option is tested" — as a test.
 *
 * `OptionUniverse` derives every option from the code. `tests/Coverage/*.php` claims
 * each one: which feature test exercises it and, for everything a person can see or
 * click, which browser test does. This file fails when an option is unclaimed, when a
 * claim points at a test that does not exist, and when the claims mention an option
 * the code no longer has. Adding a coupon type, a config key or a button therefore
 * turns this red until somebody has tested it.
 *
 * A claim is `['feature' => [...], 'browser' => [...]]`, each entry
 * `path/to/TestFile.php::fragment of the test name`. An option that genuinely cannot be
 * shown in a browser carries `'no_browser' => 'the reason'` instead of a browser entry,
 * and one that has no behaviour of its own `'no_feature' => 'the reason'`.
 */

/** @return array<string, array<string, mixed>> */
function optionCoverageClaims(): array
{
    $claims = [];

    foreach (glob(dirname(__DIR__, 2).'/Coverage/*.php') ?: [] as $file) {
        foreach ((array) require $file as $key => $claim) {
            if (isset($claims[$key])) {
                throw new RuntimeException("Option {$key} is claimed twice (again in ".basename($file).').');
            }

            $claims[(string) $key] = (array) $claim + ['_file' => basename($file)];
        }
    }

    return $claims;
}

/** @return list<string> problems with one reference */
function optionCoverageCheckReferences(string $option, array $references): array
{
    $root = dirname(__DIR__, 3);
    $problems = [];

    foreach ($references as $reference) {
        [$path, $fragment] = array_pad(explode('::', (string) $reference, 2), 2, '');
        $file = $root.'/'.$path;

        if (! is_file($file)) {
            $problems[] = "{$option}: {$path} does not exist";

            continue;
        }

        if ($fragment === '' || ! str_contains((string) file_get_contents($file), $fragment)) {
            $problems[] = "{$option}: no test named like \"{$fragment}\" in {$path}";
        }
    }

    return $problems;
}

it('claims every option the package has', function (): void {
    $universe = OptionUniverse::all();
    $claims = optionCoverageClaims();

    $unclaimed = array_values(array_diff(array_keys($universe), array_keys($claims)));

    expect($unclaimed)->toBe(
        [],
        count($unclaimed).' option(s) no test claims: '.implode(', ', array_slice($unclaimed, 0, 40)).(count($unclaimed) > 40 ? ' …' : ''),
    );
});

it('claims nothing the package no longer has', function (): void {
    $stale = array_values(array_diff(array_keys(optionCoverageClaims()), array_keys(OptionUniverse::all())));

    expect($stale)->toBe([], 'claims for options that do not exist: '.implode(', ', $stale));
});

it('backs every claim with a feature test that exists', function (): void {
    $problems = [];

    foreach (optionCoverageClaims() as $option => $claim) {
        $feature = (array) ($claim['feature'] ?? []);

        if ($feature === [] && empty($claim['no_feature'])) {
            $problems[] = "{$option}: no feature test and no reason why not";

            continue;
        }

        array_push($problems, ...optionCoverageCheckReferences($option, $feature));
    }

    expect($problems)->toBe([], implode("\n", array_slice($problems, 0, 40)));
});

it('backs everything a person can see or click with a browser test that exists', function (): void {
    $problems = [];

    foreach (optionCoverageClaims() as $option => $claim) {
        $kind = explode(':', $option, 2)[0];
        $browser = (array) ($claim['browser'] ?? []);

        if ($browser === [] && in_array($kind, OptionUniverse::BROWSER_KINDS, true) && empty($claim['no_browser'])) {
            $problems[] = "{$option}: no browser test and no reason why not";

            continue;
        }

        array_push($problems, ...optionCoverageCheckReferences($option, $browser));
    }

    expect($problems)->toBe([], implode("\n", array_slice($problems, 0, 40)));
});

it('gives a real reason wherever a browser or feature test is waived', function (): void {
    $thin = [];

    foreach (optionCoverageClaims() as $option => $claim) {
        foreach (['no_browser', 'no_feature'] as $waiver) {
            if (array_key_exists($waiver, $claim) && mb_strlen(trim((string) $claim[$waiver])) < 25) {
                $thin[] = "{$option}: {$waiver} needs a reason, not \"{$claim[$waiver]}\"";
            }
        }
    }

    expect($thin)->toBe([], implode("\n", $thin));
});
