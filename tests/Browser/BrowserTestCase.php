<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Dusk\TestCase as DuskTestCase;

/**
 * Base class of the browser suite.
 *
 * Two processes are in play: this test process and the Dusk HTTP server. Both
 * load the providers from testbench.yaml — WorkbenchServiceProvider points them
 * at ONE shared SQLite file, one shared storage root and the fake Mollie/VIES.
 * The test process therefore manipulates the very world the browser looks at:
 * it creates billables, flips settings and delivers webhooks inline.
 */
abstract class BrowserTestCase extends DuskTestCase
{
    use InteractsWithWorkbench;
    use WithWorkbench;

    public static function applicationBasePath(): string
    {
        // Both processes must resolve THE SAME skeleton, otherwise route caches,
        // compiled views and public assets drift apart.
        return \Orchestra\Testbench\Dusk\default_skeleton_path();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareScreenshotDirectories();
        static::publishWorkbenchAssets();

        // Fresh world per test. RefreshDatabase's transaction trick cannot work
        // across processes, so this is the Dusk-classic full reset — the shared
        // file makes it visible to the server immediately. The paths go straight
        // onto the migrator (the Dusk TestCase runs no defineDatabaseMigrations
        // lifecycle of its own); the package's and wallet's migrations are
        // already registered by their providers.
        foreach ([
            dirname(__DIR__).'/database/migrations',
            dirname(__DIR__, 2).'/workbench/database/migrations',
        ] as $path) {
            $this->app['migrator']->path($path);
        }

        $this->artisan('migrate:fresh');

        File::cleanDirectory(config('filesystems.disks.local.root'));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/workbench/database/migrations');
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(array_filter([
            '--window-size=1440,1100',
            '--disable-gpu',
            '--disable-search-engine-choice-screen',
            '--force-color-profile=srgb',
            '--hide-scrollbars',
            env('DUSK_HEADED') ? null : '--headless=new',
        ]));

        return RemoteWebDriver::create(
            'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }

    /**
     * The layouts request @vite assets, so the built bundle has to sit in the
     * SERVED skeleton's public directory. Copied once per process; `npm run
     * build` refreshes the source of truth under workbench/public/build.
     */
    protected static function publishWorkbenchAssets(): void
    {
        static $published = false;

        if ($published) {
            return;
        }

        $source = dirname(__DIR__, 2).'/workbench/public/build';
        $target = static::applicationBasePath().'/public/build';

        if (! is_dir($source)) {
            static::fail('Workbench assets missing — run `npm run build` (or `composer browser:assets`) first.');
        }

        File::ensureDirectoryExists(dirname($target));
        File::copyDirectory($source, $target);

        $published = true;
    }

    protected function prepareScreenshotDirectories(): void
    {
        $base = __DIR__.'/screenshots';

        File::ensureDirectoryExists($base);
        File::ensureDirectoryExists(__DIR__.'/console');
        File::ensureDirectoryExists(__DIR__.'/source');

        Browser::$storeScreenshotsAt = $base;
        Browser::$storeConsoleLogAt = __DIR__.'/console';
        Browser::$storeSourceAt = __DIR__.'/source';
    }
}
