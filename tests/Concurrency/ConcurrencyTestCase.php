<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Concurrency;

use GraystackIT\MollieBilling\MollieBilling;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;

/**
 * The test-process side of the concurrency suite.
 *
 * No RefreshDatabase: its transaction would be invisible to the workers. Each case gets a
 * directory of its own (SQLite file, cache, storage, Mollie log) and a database migrated
 * fresh — the SQLite file there, or the suite's own Postgres database — and the directory is
 * removed afterwards — set CONCURRENCY_KEEP=1 to keep it for a look at `laravel.log`.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    private ?string $worldDirectory = null;

    protected function setUp(): void
    {
        // Postgres is opt-in (BILLING_CONCURRENCY_DB=pgsql). Unreachable, the run says why
        // and skips, rather than failing on a server this machine does not have.
        if (ConcurrencyWorld::driver() === 'pgsql' && ($reason = ConcurrencyWorld::preparePgsql()) !== null) {
            $this->markTestSkipped('No PostgreSQL server for the concurrency suite: '.$reason);
        }

        if (ConcurrencyWorld::cacheDriver() === 'redis' && ConcurrencyWorld::rawRedis($redisReason) === null) {
            $this->markTestSkipped('No Redis for the concurrency suite: '.$redisReason);
        }

        $this->worldDirectory = sys_get_temp_dir().'/billing-concurrency-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->worldDirectory, 0755, true);
        touch($this->worldDirectory.'/database.sqlite');

        $_SERVER[ConcurrencyWorld::ENV_DIRECTORY] = $_ENV[ConcurrencyWorld::ENV_DIRECTORY] = $this->worldDirectory;
        putenv(ConcurrencyWorld::ENV_DIRECTORY.'='.$this->worldDirectory);
        putenv('CONCURRENCY_SLOW_REQUESTS');
        putenv('CONCURRENCY_DELAY_MS');
        putenv('CONCURRENCY_WORKER');

        parent::setUp();

        foreach (ConcurrencyWorld::migrationPaths() as $path) {
            $this->app['migrator']->path($path);
        }

        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function getPackageProviders($app): array
    {
        return ConcurrencyWorld::providers();
    }

    protected function tearDown(): void
    {
        MollieBilling::flushCallbacks();

        if ($this->worldDirectory !== null && ConcurrencyWorld::cacheDriver() === 'redis') {
            ConcurrencyWorld::flushRedisPrefix();
        }

        parent::tearDown();

        if ($this->worldDirectory !== null && ! getenv('CONCURRENCY_KEEP')) {
            // After parent::tearDown() the facades have no application any more.
            (new Filesystem)->deleteDirectory($this->worldDirectory);
        }

        unset($_SERVER[ConcurrencyWorld::ENV_DIRECTORY], $_ENV[ConcurrencyWorld::ENV_DIRECTORY]);
        putenv(ConcurrencyWorld::ENV_DIRECTORY);
    }
}
