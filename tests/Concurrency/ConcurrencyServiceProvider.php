<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Concurrency;

use GraystackIT\MollieBilling\Testing\FakeVatCalculator;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Mpociot\VatCalculator\VatCalculator;
use Workbench\App\FakeMollie\FakeMollieHttpAdapter;

/**
 * One world for several PHP processes: the test process and its workers.
 *
 * Loaded last by both (see {@see ConcurrencyWorld::providers()}), and everything it sets is
 * keyed on the directory in `BILLING_CONCURRENCY_DIR`, so every process of one test case
 * shares:
 *
 * - one database: by default a SQLite file — with a `busy_timeout`, like the browser
 *   suite, and with `IMMEDIATE` transactions. The browser suite works around SQLite
 *   refusing a read transaction's upgrade to a write without consulting the busy handler;
 *   here both processes open transactions on purpose at the same moment, so a transaction
 *   takes the write lock when it begins and the other one waits for it instead of failing.
 *   With `BILLING_CONCURRENCY_DB=pgsql` a PostgreSQL database instead, where row locks
 *   (`lockForUpdate()`) are real and transactions do not serialise on their own;
 * - one cache store — the one `Cache::lock()` uses, i.e. the locks under test, and the
 *   one bavix's wallet lock uses: a file store in that directory, or with
 *   `BILLING_CONCURRENCY_CACHE=redis` Redis under a key prefix of the test case's own;
 * - one fake Mollie (the workbench's, answered from the same SQLite file), slowed down and
 *   logged by {@see SlowFakeMollieHttpAdapter}.
 *
 * The browser suite's own WorkbenchServiceProvider is not reused: it points every process
 * at `workbench/database/workbench.sqlite` and applies whatever per-test settings a browser
 * scenario left in it.
 */
final class ConcurrencyServiceProvider extends ServiceProvider
{
    /** @var array<int, string> */
    private array $validVatNumbers = [];

    public function register(): void
    {
        $directory = ConcurrencyWorld::directory();

        config([
            'app.key' => 'base64:'.base64_encode('concurrency-suite-key-32-bytes!!'),
            'database.default' => 'concurrency',
            'database.connections.concurrency' => ConcurrencyWorld::connection(),
            'cache.default' => 'file',
            'cache.stores.file.path' => $directory.'/cache',
            'cache.stores.file.lock_path' => $directory.'/cache',
            'wallet.cache.driver' => 'file',
            'wallet.lock.driver' => 'file',
            'filesystems.disks.local.root' => $directory.'/storage',
            'logging.default' => 'concurrency',
            'logging.channels.concurrency' => ['driver' => 'single', 'path' => $directory.'/laravel.log'],
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'session.driver' => 'array',
            'mollie.key' => 'test_concurrencyconcurrencyconcurrency',

            'mollie-billing.billable_model' => TestBillable::class,
            'mollie-billing.billable_key_type' => 'int',
            'mollie-billing.currency' => 'EUR',
            'mollie-billing.vat.seller_country' => 'AT',
            'mollie-billing.invoices.disk' => 'local',
            'mollie-billing.invoices.seller.company' => 'Concurrency GmbH',
            'mollie-billing.invoices.seller.email' => 'billing@concurrency.test',
            'mollie-billing.invoices.seller.address.street' => 'Teststraße 1',
            'mollie-billing.invoices.seller.address.city' => 'Wien',
            'mollie-billing.invoices.seller.address.postal_code' => '1010',
            'mollie-billing.invoices.seller.address.country' => 'AT',

            'mollie-billing-plans' => [
                'plans' => [
                    'team' => [
                        'name' => 'Team',
                        'tier' => 1,
                        'included_seats' => 1,
                        'feature_keys' => [],
                        'allowed_addons' => [],
                        'intervals' => [
                            'monthly' => [
                                'base_price_net' => 2000,
                                'seat_price_net' => 500,
                                // SMS has no overage price: past the quota it is refused.
                                'included_usages' => ['Tokens' => 50, 'SMS' => 50],
                                'usage_overage_prices' => ['Tokens' => 10],
                            ],
                        ],
                    ],
                ],
                'addons' => [],
                'feature_groups' => [],
                'features' => [],
                'product_groups' => [],
                'products' => [],
            ],
        ]);

        // BILLING_CONCURRENCY_CACHE=redis: the locks under test, the cache and bavix's wallet
        // lock and balance cache on Redis instead of the file store — every key under a
        // prefix of this test case's own, removed again by ConcurrencyTestCase.
        if (ConcurrencyWorld::cacheDriver() === 'redis') {
            config([
                'database.redis.client' => 'phpredis',
                'database.redis.options.prefix' => ConcurrencyWorld::redisPrefix(),
                'database.redis.default' => ConcurrencyWorld::redisConnection(),
                'database.redis.cache' => ConcurrencyWorld::redisConnection(),
                'cache.default' => 'redis',
                'cache.prefix' => '',
                'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
                'wallet.cache.driver' => 'redis',
                'wallet.lock.driver' => 'redis',
            ]);
        }

        @mkdir($directory.'/cache', 0755, true);
        @mkdir($directory.'/storage', 0755, true);

        $this->app->bind(MollieApiClient::class, function (): MollieApiClient {
            $slow = array_values(array_filter(explode(',', (string) getenv('CONCURRENCY_SLOW_REQUESTS'))));

            $client = new MollieApiClient(new SlowFakeMollieHttpAdapter(
                new FakeMollieHttpAdapter,
                ConcurrencyWorld::directory(),
                $slow,
                (int) (getenv('CONCURRENCY_DELAY_MS') ?: 0),
            ));
            $client->setApiKey('test_concurrencyconcurrencyconcurrency');

            return $client;
        });

        // VIES without the SOAP service, as in the workbench: mpociot's provider is
        // deferred and would otherwise replace this binding on first resolution.
        $this->app->register(\Mpociot\VatCalculator\VatCalculatorServiceProvider::class);
        $this->app->singleton(VatCalculator::class, fn (): VatCalculator => new FakeVatCalculator($this->validVatNumbers, config('vat_calculator', [])));
    }

    /** The routes the package builds Mollie redirect and webhook URLs from, as in the feature suite. */
    public function boot(): void
    {
        \GraystackIT\MollieBilling\MollieBilling::dashboardRoutes();
        \GraystackIT\MollieBilling\MollieBilling::webhookRoutes();
        \GraystackIT\MollieBilling\MollieBilling::checkoutRoutes();
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    }
}
