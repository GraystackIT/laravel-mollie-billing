<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use GraystackIT\MollieBilling\IpGeolocation\Contracts\IpGeolocationDriver;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\Testing\FakeVatCalculator;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Mpociot\VatCalculator\VatCalculator;
use Workbench\App\FakeMollie\FakeMollieHttpAdapter;
use Workbench\App\Models\AdminUser;
use Workbench\App\Support\WorkbenchSetting;

/**
 * Boots the browser-test workbench: a real app around the package with a fake
 * Mollie, a fake VIES and a session-based tenant — loaded identically by the
 * Dusk HTTP server and by the test process, which is what keeps the two
 * processes in the same world (same SQLite file, same catalog, same fakes).
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * VAT numbers the fake VIES reports as valid B2B businesses. Anything else
     * answers `valid: false` — exactly how VIES reports an unknown number.
     */
    private array $validVatNumbers = ['DE129273398', 'ATU69934418', 'NL863726392B01'];

    public function register(): void
    {
        $this->configureApplication();
        $this->configureBillingPackage();
        $this->configureCatalog();

        // The whole point of the workbench: Mollie answered from SQLite. Same
        // seam as the SDK's own MockMollieHttpAdapter, so every typed request
        // and every response hydration is the real SDK code path.
        $this->app->bind(MollieApiClient::class, function (): MollieApiClient {
            $client = new MollieApiClient(new FakeMollieHttpAdapter);
            $client->setApiKey('test_workbenchworkbenchworkbenchworkbench');

            return $client;
        });

        // VIES without the SOAP service. Rates and reverse-charge rules stay real.
        $this->app->singleton(VatCalculator::class, function (): VatCalculator {
            return new FakeVatCalculator($this->validVatNumbers, config('vat_calculator', []));
        });
    }

    public function boot(): void
    {
        $this->bindIpGeolocation();

        // The portal needs no Laravel auth — the billable comes from the session
        // the `/_wb/act-as/{billable}` route sets, and authorization is "you are
        // acting as exactly this billable". The admin panel authenticates a real
        // user (AdminUser) through the default guard instead.
        MollieBilling::resolveBillableUsing(function ($request = null): ?TestBillable {
            $id = session('wb_billable');

            return $id === null ? null : TestBillable::find($id);
        });

        MollieBilling::authUsing(function ($request, $billable): bool {
            return (string) session('wb_billable') === (string) $billable->getKey();
        });

        // Anonymous signup: the checkout collects the address and hands it to the
        // app to mint the billable. The session write is what a real app does via
        // its registration/login — without it the freshly signed-up customer
        // could not open the portal they just paid for.
        MollieBilling::createBillableUsing(function (array $data): TestBillable {
            $billable = new TestBillable;

            $billable->forceFill([
                'name' => $data['name'] ?: 'Signup '.uniqid(),
                'email' => 'signup-'.uniqid().'@workbench.test',
                'billing_street' => $data['billing_street'] ?? null,
                'billing_postal_code' => $data['billing_postal_code'] ?? null,
                'billing_city' => $data['billing_city'] ?? null,
                'billing_country' => $data['billing_country'] ?? null,
                'tax_country_user' => $data['tax_country_user'] ?? null,
                'tax_country_ip' => $data['tax_country_ip'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
            ])->save();

            session(['wb_billable' => $billable->getKey()]);

            return $billable->refresh();
        });

        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'wb');

        // Webhook outside the web group: no session, no CSRF — like production.
        MollieBilling::webhookRoutes();

        Route::middleware('web')->group(function (): void {
            MollieBilling::dashboardRoutes();
            MollieBilling::checkoutRoutes();
            MollieBilling::promotionRoutes();

            require dirname(__DIR__, 2).'/routes/web.php';
        });
    }

    private function configureApplication(): void
    {
        $root = dirname(__DIR__, 3);
        $database = $root.'/workbench/database/workbench.sqlite';
        $storage = $root.'/workbench/storage/app';

        @mkdir(dirname($database), 0755, true);
        @mkdir($storage, 0755, true);
        @mkdir($root.'/workbench/storage/cache', 0755, true);
        touch($database);

        config([
            'app.key' => 'base64:'.base64_encode('workbench-browser-suite-key-32b!'),
            'app.name' => 'Billing Workbench',

            // One file-backed SQLite for BOTH processes. In-memory would give the
            // Dusk server and the test process two different databases.
            //
            // Two processes on one SQLite file contend for its write lock, and PDO's
            // default busy timeout is 0 — so a collision (a webhook writing an invoice
            // while the browser's own request reads the dashboard) failed the run outright
            // with "database is locked" instead of waiting a moment. The timeout makes them
            // queue.
            //
            // NOT `journal_mode = wal`, tempting as it is: Laravel's SQLite connector
            // issues that pragma on every connect, and switching the mode needs an
            // exclusive lock — so the two processes collide on the pragma itself, which is
            // strictly worse (a full run went from 2 failures to 45).
            'database.default' => 'workbench',
            'database.connections.workbench' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 10000,
            ],

            // The invoice PDFs a webhook writes must be readable by the portal's
            // download route regardless of which process ran the webhook — so the
            // disk root is shared, not the per-process storage_path().
            'filesystems.disks.local.root' => $storage,

            'cache.default' => 'file',
            'cache.stores.file.path' => $root.'/workbench/storage/cache',

            // bavix/laravel-wallet caches balances in the `array` store by
            // default — per process, so the test process kept reading its own
            // stale balance after the SERVER credited a wallet. Pointed at the
            // shared file store instead, so one process's invalidation is the
            // other's too.
            'wallet.cache.driver' => 'file',

            'session.driver' => 'file',
            'queue.default' => 'sync',
            'mail.default' => 'log',

            'auth.providers.users.model' => AdminUser::class,

            'mollie.key' => 'test_workbenchworkbenchworkbenchworkbench',
        ]);
    }

    private function configureBillingPackage(): void
    {
        config([
            'mollie-billing.billable_model' => TestBillable::class,
            'mollie-billing.billable_key_type' => 'int',
            'mollie-billing.user_key_type' => 'int',
            'mollie-billing.currency' => 'EUR',
            'mollie-billing.vat.seller_country' => 'AT',
            'mollie-billing.checkout_country_fallback' => 'DE',
            'mollie-billing.invoices.disk' => 'local',
            'mollie-billing.invoices.seller.company' => 'Workbench GmbH',
            'mollie-billing.invoices.seller.email' => 'billing@workbench.test',
            'mollie-billing.invoices.seller.tax_number' => 'ATU69934418',
            'mollie-billing.invoices.seller.address.street' => 'Teststraße 1',
            'mollie-billing.invoices.seller.address.city' => 'Wien',
            'mollie-billing.invoices.seller.address.postal_code' => '1010',
            'mollie-billing.invoices.seller.address.country' => 'AT',
        ]);
    }

    /**
     * A catalog wide enough to exercise everything the package sells: three
     * visible tiers (upgrade AND downgrade paths), a trial plan, seat pricing,
     * two addons, metered usage with overage prices, and one-time products —
     * including a once-only one.
     */
    private function configureCatalog(): void
    {
        config(['mollie-billing-plans' => [
            'plans' => [
                'starter' => [
                    'name' => 'Starter',
                    'description' => 'For solo founders getting going.',
                    'tier' => 1,
                    'included_seats' => 1,
                    'feature_keys' => ['dashboard'],
                    'allowed_addons' => ['softdrinks'],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 990,
                            'seat_price_net' => null,
                            'included_usages' => ['Tokens' => 50, 'SMS' => 20],
                            'usage_overage_prices' => ['Tokens' => 12, 'SMS' => 18],
                        ],
                        'yearly' => [
                            'base_price_net' => 9900,
                            'seat_price_net' => null,
                            'included_usages' => ['Tokens' => 600, 'SMS' => 240],
                            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                        ],
                    ],
                ],
                'pro' => [
                    'name' => 'Pro',
                    'description' => 'For teams that need seats and reports.',
                    'tier' => 2,
                    'included_seats' => 3,
                    'feature_keys' => ['dashboard', 'advanced-reports'],
                    'allowed_addons' => ['softdrinks', 'priority-support'],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 2900,
                            'seat_price_net' => 990,
                            'trial_days' => 14,
                            'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                        ],
                        'yearly' => [
                            'base_price_net' => 29000,
                            'seat_price_net' => 9900,
                            'trial_days' => 14,
                            'included_usages' => ['Tokens' => 1500, 'SMS' => 600],
                            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                        ],
                    ],
                ],
                'business' => [
                    'name' => 'Business',
                    'description' => 'Everything, plus priority support.',
                    'tier' => 3,
                    'included_seats' => 10,
                    'feature_keys' => ['dashboard', 'advanced-reports', 'api-access'],
                    'allowed_addons' => ['softdrinks', 'priority-support'],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 9900,
                            'seat_price_net' => 790,
                            'included_usages' => ['Tokens' => 1000, 'SMS' => 500],
                            'usage_overage_prices' => ['Tokens' => 8, 'SMS' => 12],
                        ],
                        'yearly' => [
                            'base_price_net' => 99000,
                            'seat_price_net' => 7900,
                            'included_usages' => ['Tokens' => 12000, 'SMS' => 6000],
                            'usage_overage_prices' => ['Tokens' => 8, 'SMS' => 12],
                        ],
                    ],
                ],
            ],

            'usage_types' => [
                'Tokens' => ['rollover' => true],
                'SMS' => ['rollover' => false],
            ],

            'features' => [
                'dashboard' => ['name' => 'Dashboard', 'description' => 'The main dashboard.'],
                'advanced-reports' => ['name' => 'Advanced Reports', 'description' => 'Detailed analytics.'],
                'api-access' => ['name' => 'API Access', 'description' => 'Full REST API.'],
                'softdrinks' => ['name' => 'Softdrinks', 'description' => 'Unlimited softdrinks.'],
                'priority-support' => ['name' => 'Priority Support', 'description' => 'Answers within 4 hours.'],
            ],

            'addons' => [
                'softdrinks' => [
                    'name' => 'Softdrinks',
                    'feature_keys' => ['softdrinks'],
                    'intervals' => [
                        'monthly' => ['price_net' => 490],
                        'yearly' => ['price_net' => 4900],
                    ],
                ],
                'priority-support' => [
                    'name' => 'Priority Support',
                    'feature_keys' => ['priority-support'],
                    'intervals' => [
                        'monthly' => ['price_net' => 990],
                        'yearly' => ['price_net' => 9900],
                    ],
                ],
            ],

            'product_groups' => [
                'top-ups' => ['name' => 'Top-Ups', 'sort' => 1],
                'services' => ['name' => 'Services', 'sort' => 2],
            ],

            'products' => [
                'token-pack-500' => [
                    'name' => '500 Token Pack',
                    'description' => 'Top up your account with 500 tokens.',
                    'image_url' => null,
                    'price_net' => 4900,
                    'usage_type' => 'Tokens',
                    'quantity' => 500,
                    'group' => 'top-ups',
                ],
                'onboarding-session' => [
                    'name' => 'Onboarding Session',
                    'description' => 'A one-hour guided onboarding. Can only be booked once.',
                    'image_url' => null,
                    'price_net' => 14900,
                    'onetimeonly' => true,
                    'group' => 'services',
                ],
            ],
        ]]);
    }

    /**
     * Same driver-manager pattern MollieBillingFake uses, but reading the shared
     * settings table: the test process flips `ip_country`, the server's next
     * three-way country check sees it.
     */
    private function bindIpGeolocation(): void
    {
        $this->app->singleton(IpGeolocationManager::class, function ($app): IpGeolocationManager {
            $manager = new IpGeolocationManager($app);

            $manager->extend('workbench', fn (): IpGeolocationDriver => new class implements IpGeolocationDriver
            {
                public function getCountry(string $ip): ?string
                {
                    return WorkbenchSetting::get('ip_country');
                }
            });

            return $manager;
        });

        config(['mollie-billing.ip_geolocation.driver' => 'workbench']);
    }
}
