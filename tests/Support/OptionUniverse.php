<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Every option this package has, enumerated from the code itself.
 *
 * "Every option is tested" is a claim that rots the day somebody adds a coupon type, a
 * config key or a button. So the list is not written down — it is derived: enum cases
 * from the enum classes, config keys from the shipped config files, coupon fields from
 * the table, actions from the Livewire components, screens from the routes, commands,
 * events, notifications and jobs from their directories. `tests/Coverage/*.php` then
 * says, per option, which feature test and which browser test exercises it, and
 * `tests/Feature/Coverage/OptionCoverageTest.php` fails for any option nobody claimed.
 *
 * The keys are stable strings of the form `<kind>:<name>`.
 */
final class OptionUniverse
{
    /** Kinds that must be shown working in a real browser, not only in a feature test. */
    public const BROWSER_KINDS = ['enum', 'plan_config', 'coupon_field', 'action', 'screen'];

    /** Livewire lifecycle hooks: not something a user can trigger. */
    private const LIFECYCLE = [
        'mount', 'with', 'boot', 'booted', 'hydrate', 'dehydrate', 'render', 'rendering',
        'rendered', 'placeholder', 'exception', 'rules', 'messages', 'validationAttributes',
    ];

    /**
     * @return array<string, string> option key => where it comes from
     */
    public static function all(): array
    {
        return array_merge(
            self::enumCases(),
            self::configKeys(),
            self::planConfigKeys(),
            self::couponFields(),
            self::componentActions(),
            self::screens(),
            self::classesIn('Commands', 'command'),
            self::classesIn('Events', 'event'),
            self::classesIn('Notifications', 'notification'),
            self::classesIn('Jobs', 'job'),
        );
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private static function enumCases(): array
    {
        $out = [];

        foreach (glob(self::root().'/src/Enums/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'GraystackIT\\MollieBilling\\Enums\\'.$short;

            if (! enum_exists($class)) {
                continue;
            }

            foreach ($class::cases() as $case) {
                $out['enum:'.$short.'.'.$case->name] = 'src/Enums/'.$short.'.php';
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function configKeys(): array
    {
        $config = require self::root().'/config/mollie-billing.php';
        $out = [];

        foreach (self::leaves((array) $config, 2) as $key) {
            $out['config:'.$key] = 'config/mollie-billing.php';
        }

        return $out;
    }

    /**
     * The shape of the catalog: every key a plan, an interval, an addon, a feature, a
     * product or a usage type can carry, with the codes wildcarded. Taken from the
     * shipped example config AND from what `billing:check-config` reads, so a key that
     * is supported but not in the example is still listed.
     *
     * @return array<string, string>
     */
    private static function planConfigKeys(): array
    {
        $config = (array) require self::root().'/config/mollie-billing-plans.php';
        $shapes = [];

        foreach ($config as $section => $entries) {
            if (! is_array($entries)) {
                $shapes[(string) $section] = true;

                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $shapes[$section.'.*'] = true;

                    continue;
                }

                foreach ($entry as $key => $value) {
                    if ($key === 'intervals' && is_array($value)) {
                        foreach ($value as $interval) {
                            foreach (array_keys((array) $interval) as $intervalKey) {
                                $shapes[$section.'.*.intervals.*.'.$intervalKey] = true;
                            }
                        }

                        continue;
                    }

                    $shapes[$section.'.*.'.$key] = true;
                }
            }
        }

        // Supported keys the shipped example does not happen to use.
        foreach ([
            'plans.*.hidden',
            'plans.*.intervals.*.trial_days',
            'plans.*.intervals.*.usage_overage_prices',
            'addons.*.included_seats',
            'addons.*.intervals.*.seat_price_net',
            'features.*.visible',
        ] as $extra) {
            $shapes[$extra] = true;
        }

        ksort($shapes);

        $out = [];
        foreach (array_keys($shapes) as $shape) {
            $out['plan_config:'.$shape] = 'config/mollie-billing-plans.php';
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function couponFields(): array
    {
        $skip = ['id', 'created_at', 'updated_at', 'redemptions_count', 'currency'];
        $out = [];

        foreach (Schema::getColumnListing('coupons') as $column) {
            if (! in_array($column, $skip, true)) {
                $out['coupon_field:'.$column] = 'coupons table';
            }
        }

        return $out;
    }

    /**
     * Every public method of every Livewire component: all of them can be called from a
     * browser, whether a button exists for them or not.
     *
     * @return array<string, string>
     */
    private static function componentActions(): array
    {
        $out = [];
        $base = self::root().'/resources/views/livewire/billing';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }

            $relative = substr((string) $file, strlen($base) + 1, -strlen('.blade.php'));
            $source = (string) file_get_contents((string) $file);

            // Only the component class, not `function` words inside the markup.
            $end = strpos($source, '?>');
            $php = $end === false ? '' : substr($source, 0, $end);

            if (! preg_match_all('/^\s*public function (\w+)\s*\(/m', $php, $matches)) {
                continue;
            }

            foreach ($matches[1] as $method) {
                if (in_array($method, self::LIFECYCLE, true) || preg_match('/^(updat(ed|ing)|get\w+Property$)/', $method)) {
                    continue;
                }

                $out['action:'.str_replace('/', '.', $relative).'@'.$method] = 'resources/views/livewire/billing/'.$relative.'.blade.php';
            }
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function screens(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name === '' || ! str_contains($name, 'billing')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $out['screen:'.$name] = 'route '.$route->uri();
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function classesIn(string $directory, string $kind): array
    {
        $out = [];

        foreach (glob(self::root().'/src/'.$directory.'/*.php') ?: [] as $file) {
            $out[$kind.':'.basename($file, '.php')] = 'src/'.$directory.'/'.basename($file);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function leaves(array $config, int $depth, string $prefix = ''): array
    {
        $out = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && $depth > 1 && ! array_is_list($value)) {
                array_push($out, ...self::leaves($value, $depth - 1, $path));
            } else {
                $out[] = $path;
            }
        }

        return $out;
    }
}
