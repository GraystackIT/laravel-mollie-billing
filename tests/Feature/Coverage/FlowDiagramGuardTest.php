<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\SubscriptionSource;
use GraystackIT\MollieBilling\Enums\SubscriptionStatus;

/*
 * "docs/flows.md describes the whole package" — as a test.
 *
 * `docs/flows.md` is the overview of every booking flow, and it is only worth anything
 * while it is complete. This file derives from the code what the overview has to name and
 * fails with the list of what is missing: every service, webhook handler, wallet service,
 * job, controller and event, and every case of the three enums the flows branch on. Adding
 * a handler, a job, an event or a coupon type therefore turns this red until the overview
 * says where it belongs.
 *
 * And the other way round: every class the overview names has to exist — in the package
 * or in the Mollie SDK — and so does every `Class::method()` it cites. A building block
 * that was renamed or deleted is found here instead of by a reader months later.
 *
 * What this cannot check is whether a diagram is right. That stays with the author of the
 * change; see the "Source of truth" section in CLAUDE.md.
 */

/**
 * Classes the overview does not have to name, each with the reason.
 *
 * Meant for pure value objects that take no part in any flow. A reason is a sentence
 * (25 characters or more), an entry has to be a class the guard would otherwise demand,
 * and one that the overview names anyway is reported as needless. Empty on purpose: so
 * far every class has a place in a flow.
 *
 * @return array<string, string> short class name => reason
 */
function flowGuardExemptions(): array
{
    return [];
}

/** Directories whose classes are the building blocks of a flow. */
const FLOW_GUARD_DIRECTORIES = [
    'src/Services/Billing',
    'src/Services/Webhook',
    'src/Services/Wallet',
    'src/Jobs',
    'src/Http/Controllers',
    'src/Events',
];

/** Suffixes that make a word a class name wherever it stands, diagrams included. */
const FLOW_GUARD_CLASS_SUFFIXES = 'Job|Handler|Service|Request|Exception|Notification';

function flowGuardRoot(): string
{
    return dirname(__DIR__, 3);
}

function flowGuardDocument(): string
{
    $path = flowGuardRoot().'/docs/flows.md';

    if (! is_file($path)) {
        throw new RuntimeException('docs/flows.md does not exist.');
    }

    return (string) file_get_contents($path);
}

/** @return list<string> short names of the classes the overview has to name */
function flowGuardRequiredClasses(): array
{
    $names = [];

    foreach (FLOW_GUARD_DIRECTORIES as $directory) {
        $files = glob(flowGuardRoot().'/'.$directory.'/*.php') ?: [];

        if ($files === []) {
            throw new RuntimeException("No classes found in {$directory} — has it moved?");
        }

        foreach ($files as $file) {
            $names[] = basename($file, '.php');
        }
    }

    sort($names);

    return array_values(array_unique($names));
}

function flowGuardMentions(string $document, string $name): bool
{
    return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $document) === 1;
}

/**
 * Every class the package or the Mollie SDK has, by short name.
 *
 * @return array<string, list<string>> short name => files
 */
function flowGuardKnownClasses(): array
{
    static $known = null;

    if ($known !== null) {
        return $known;
    }

    $known = [];

    foreach ([flowGuardRoot().'/src', flowGuardRoot().'/vendor/mollie/mollie-api-php/src'] as $directory) {
        if (! is_dir($directory)) {
            throw new RuntimeException("{$directory} does not exist.");
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $known[$file->getBasename('.php')][] = $file->getPathname();
            }
        }
    }

    return $known;
}

/** @return list<string> the text of every `inline code` span */
function flowGuardCodeSpans(string $document): array
{
    preg_match_all('/`([^`\n]+)`/', $document, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Everything in the overview that claims to be a class.
 *
 * Two sources: a code span that starts with a CamelCase identifier (`PlanWaiver`,
 * `CouponService::redeem()`), and — anywhere, diagram labels included, since those carry
 * no backticks — a word with one of the class suffixes.
 *
 * @return list<string>
 */
function flowGuardCitedClasses(string $document): array
{
    $cited = [];

    foreach (flowGuardCodeSpans($document) as $span) {
        if (preg_match('/^\\\\?([A-Z][a-z0-9]+(?:[A-Z][A-Za-z0-9]*)+)(?:::|\(|$)/', $span, $match) === 1) {
            $cited[] = $match[1];
        }
    }

    preg_match_all('/(?<![A-Za-z0-9_])[A-Z][A-Za-z0-9]+(?:'.FLOW_GUARD_CLASS_SUFFIXES.')(?![A-Za-z0-9_])/', $document, $matches);

    $cited = array_values(array_unique([...$cited, ...$matches[0]]));
    sort($cited);

    return $cited;
}

it('names every service, handler, job, controller and event in docs/flows.md', function (): void {
    $document = flowGuardDocument();
    $exempt = flowGuardExemptions();

    $missing = array_values(array_filter(
        flowGuardRequiredClasses(),
        static fn (string $name): bool => ! array_key_exists($name, $exempt) && ! flowGuardMentions($document, $name),
    ));

    expect($missing)->toBe(
        [],
        count($missing).' class(es) docs/flows.md does not name — add each to the flow it belongs to: '.implode(', ', $missing),
    );
});

it('names every coupon type, subscription status and subscription source in docs/flows.md', function (): void {
    $document = flowGuardDocument();
    $missing = [];

    foreach ([CouponType::class, SubscriptionStatus::class, SubscriptionSource::class] as $enum) {
        $short = class_basename($enum);

        foreach ($enum::cases() as $case) {
            $byName = str_contains($document, $short.'::'.$case->name);
            $byValue = str_contains($document, '`'.$case->value.'`');

            if (! $byName && ! $byValue) {
                $missing[] = $short.'::'.$case->name;
            }
        }
    }

    expect($missing)->toBe(
        [],
        'docs/flows.md does not describe: '.implode(', ', $missing).' (written as Enum::Case or as the value in backticks)',
    );
});

it('exempts a class from docs/flows.md only with a reason, and only while that is needed', function (): void {
    $document = flowGuardDocument();
    $required = flowGuardRequiredClasses();
    $problems = [];

    foreach (flowGuardExemptions() as $name => $reason) {
        if (mb_strlen(trim((string) $reason)) < 25) {
            $problems[] = "{$name}: an exemption needs a reason, not \"{$reason}\"";
        }

        if (! in_array($name, $required, true)) {
            $problems[] = "{$name}: exempted, but the package has no such class any more";
        } elseif (flowGuardMentions($document, (string) $name)) {
            $problems[] = "{$name}: exempted, but docs/flows.md names it — remove the exemption";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('names no class in docs/flows.md that does not exist', function (): void {
    $known = flowGuardKnownClasses();

    $unknown = array_values(array_filter(
        flowGuardCitedClasses(flowGuardDocument()),
        static fn (string $name): bool => ! array_key_exists($name, $known),
    ));

    expect($unknown)->toBe(
        [],
        'docs/flows.md names '.count($unknown).' class(es) that exist neither in src/ nor in the Mollie SDK (renamed or deleted?): '.implode(', ', $unknown),
    );
});

it('cites no method in docs/flows.md that its class does not have', function (): void {
    $known = flowGuardKnownClasses();
    $unknown = [];

    foreach (flowGuardCodeSpans(flowGuardDocument()) as $span) {
        if (preg_match('/^\\\\?([A-Z][A-Za-z0-9]+)::([a-z][A-Za-z0-9]*)\(\)$/', $span, $match) !== 1) {
            continue;
        }

        [, $class, $method] = $match;

        // An unknown class is the previous test's finding, not this one's.
        foreach ($known[$class] ?? [] as $file) {
            if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', (string) file_get_contents($file)) === 1) {
                continue 2;
            }
        }

        if (isset($known[$class])) {
            $unknown[] = $span;
        }
    }

    expect($unknown)->toBe(
        [],
        'docs/flows.md cites method(s) that do not exist (renamed?): '.implode(', ', $unknown),
    );
});

it('finds classes to guard at all', function (): void {
    // A guard that derives nothing passes everything.
    expect(count(flowGuardRequiredClasses()))->toBeGreaterThan(100)
        ->and(count(flowGuardCitedClasses(flowGuardDocument())))->toBeGreaterThan(100);
});
