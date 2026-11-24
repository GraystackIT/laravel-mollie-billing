<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingTime;
use GraystackIT\MollieBilling\Testing\TestBillable;

/*
 * "The admin panel works in UTC, and says so" — as a test.
 *
 * Every time the panel shows goes through `<x-mollie-billing::admin.time>` (markup) or
 * `BillingTime::formatUtc()` (text), both of which append "UTC". A bare
 * `displayUtc(...)->format(...)` or `$model->created_at->format(...)` in an admin view is
 * a time without its zone, and an operator reads it against their own clock. The header
 * says it once for every page as well.
 */

/**
 * Formatting that is not a moment shown to the operator, each with why.
 *
 * @return array<string, string> fragment of the line => reason
 */
function adminUtcGuardAllowed(): array
{
    return [
        ":min=\"BillingTime::nowUtc()->addDay()->format('Y-m-d')\"" => 'the lower bound of a date input, not shown as text; the input is labelled (UTC)',
        "\$mandateExpiry->format('m/Y')" => 'the expiry month printed on a payment card, not a moment',
        "->setTimezone('UTC')->format('Y')" => 'the year range of the OSS export picker, computed in UTC',
        "BillingTime::billingDay(\$detail->period_start)->format('Y-m-d').' – '.(BillingTime::formatUtc(" => 'a period: the end carries the UTC label for both days',
    ];
}

it('lets no admin view print a time without saying it is UTC', function (): void {
    $root = dirname(__DIR__, 3);
    $files = array_merge(
        glob($root.'/resources/views/layouts/admin.blade.php') ?: [],
        glob($root.'/resources/views/components/admin/*.blade.php') ?: [],
    );

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views/livewire/billing/admin', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_ends_with((string) $file, '.blade.php')) {
            $files[] = (string) $file;
        }
    }

    $offenders = [];

    foreach ($files as $file) {
        if (str_ends_with($file, 'components/admin/time.blade.php')) {
            continue;
        }

        foreach (file($file) as $number => $line) {
            $bare = str_contains($line, 'displayUtc(')
                || (preg_match("/->format\\('[^']*[YmdHi][^']*'\\)/", $line) === 1 && ! str_contains($line, 'formatUtc('));

            if (! $bare) {
                continue;
            }

            foreach (array_keys(adminUtcGuardAllowed()) as $fragment) {
                if (str_contains($line, $fragment)) {
                    continue 2;
                }
            }

            $offenders[] = str_replace($root.'/', '', $file).':'.($number + 1).'  '.trim($line);
        }
    }

    expect($offenders)->toBe([], "Admin views printing a time without the UTC label — use <x-mollie-billing::admin.time> or BillingTime::formatUtc():\n".implode("\n", $offenders));
});

it('renders a moment with its UTC label, from a model value or a plain string', function (): void {
    $html = (string) $this->blade('<x-mollie-billing::admin.time :at="$at" />', [
        'at' => \Carbon\CarbonImmutable::parse('2026-11-24 15:03:00', 'Europe/Vienna'),
    ]);

    expect($html)->toContain('datetime="2026-11-24T14:03:00+00:00"')
        ->toContain('2026-11-24 14:03 <span')
        ->toContain('UTC</span>');

    expect((string) $this->blade('<x-mollie-billing::admin.time at="2026-11-24 15:03:00" format="Y-m-d" />'))
        ->toContain('2026-11-24 <span');

    expect(trim((string) $this->blade('<x-mollie-billing::admin.time :at="null" empty="never" />')))->toBe('never')
        ->and(BillingTime::formatUtc('2026-11-24 15:03:00'))->toBe('2026-11-24 15:03 UTC')
        ->and(BillingTime::formatUtc(\Carbon\CarbonImmutable::parse('2026-11-24 16:03', 'Europe/Vienna'), 'Y-m-d H:i'))->toBe('2026-11-24 15:03 UTC')
        ->and(BillingTime::formatUtc(null))->toBeNull();
});

it('says on every admin page that the panel works in UTC', function (): void {
    $this->withoutVite();

    $html = view('mollie-billing::layouts.admin', [
        'livewireComponent' => 'mollie-billing::admin.dashboard',
    ])->render();

    expect($html)->toContain(__('billing::admin.utc.badge'))
        ->toContain('dusk="admin-utc"');
});
