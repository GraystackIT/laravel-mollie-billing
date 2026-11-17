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
use Workbench\App\Support\SharedClock;
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
        //
        // mpociot's provider is deferred: it registers its own `VatCalculator` binding the
        // first time the class is resolved — which silently replaced a closure bound here,
        // so the browser suite was asking the real VIES service (slow, flaky, and it knew
        // only the one real number among the three). Registering it now makes it run
        // first (and merge its config), so the binding below is the one that stays.
        $this->app->register(\Mpociot\VatCalculator\VatCalculatorServiceProvider::class);

        $this->app->singleton(VatCalculator::class, function (): VatCalculator {
            return new FakeVatCalculator($this->validVatNumbers, config('vat_calculator', []));
        });

        $this->applyConfigOverrides();
        $this->applySharedClock();

        // Per-test switch: the booking services answer "past due" (see the class).
        \Workbench\App\Support\PastDueBookingRace::bindIfSwitchedOn($this->app);
    }

    /**
     * The browser suite's shared clock (`Workbench\App\Support\SharedClock`), on the server
     * side: every request the Dusk server boots runs at the time the test travelled to.
     *
     * Only in the server (`php -S` is the `cli-server` SAPI). The test process sets its own
     * clock when it travels, and must never pick up a row an earlier test left behind while
     * its application boots — before `migrate:fresh` has cleared the table.
     *
     * With a travelled clock the session's own clock has to agree: the file session handler
     * compares the session file's real modification time with `now() - lifetime`, so a clock
     * months ahead read every session as expired and logged the browser out on each request;
     * and a cookie stamped with an expiry in the travelled past would be dropped by the
     * browser, which keeps the machine's time. A lifetime of ten years covers both.
     */
    private function applySharedClock(): void
    {
        if (PHP_SAPI !== 'cli-server') {
            return;
        }

        try {
            $travelled = SharedClock::applyFromSettings();
        } catch (\Throwable) {
            return; // no settings table yet (migrate:fresh is running)
        }

        if ($travelled) {
            config(['session.lifetime' => 60 * 24 * 365 * 10]);
        }
    }

    /**
     * Per-test configuration, for both processes.
     *
     * A test that wants the SERVER to run with a different setting (a logo, a pinned admin
     * locale, a lower usage threshold) cannot call `config()` — that only reaches its own
     * process. It writes a `config.<dotted key>` row instead (JSON value, see
     * `InteractsWithWorkbench`-style helpers in tests/Browser/Matrix), and every request
     * the Dusk server boots picks it up here. In `register()`, not `boot()`: the package's
     * own provider reads some keys while booting (route auto-loading, the schedule), and
     * it boots before this one.
     */
    /**
     * Opt-in: give the request a public client IP.
     *
     * A browser test reaches the server from 127.0.0.1, and IpGeolocationManager answers
     * `null` for a private address before any driver is asked — so nothing that depends on
     * the visitor's country (the country gate, the preselected checkout country) could be
     * shown in a browser. A test sets `client_ip`; without it nothing changes.
     */
    private function applyClientIpOverride(): void
    {
        try {
            $ip = WorkbenchSetting::get('client_ip');
        } catch (\Throwable) {
            return; // no settings table yet (migrate:fresh is running)
        }

        if (! is_string($ip) || $ip === '') {
            return;
        }

        // The Dusk server boots the application before it captures the request, so the
        // superglobal is what the request will be built from; a request that already
        // exists is corrected as well.
        $_SERVER['REMOTE_ADDR'] = $ip;

        if ($this->app->bound('request')) {
            $this->app['request']->server->set('REMOTE_ADDR', $ip);
        }
    }

    private function applyConfigOverrides(): void
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::table('workbench_settings')
                ->where('key', 'like', 'config.%')
                ->get();
        } catch (\Throwable) {
            // No table yet (migrate:fresh is running) — nothing to override.
            return;
        }

        foreach ($rows as $row) {
            $key = substr((string) $row->key, 7);
            $value = json_decode((string) $row->value, true);

            // The one key the package reads as an enum instance, not a scalar.
            if ($key === 'mollie-billing.plan_change_mode' && is_string($value)) {
                $value = \GraystackIT\MollieBilling\Enums\PlanChangeMode::from($value);
            }

            config([$key => $value]);
        }
    }

    public function boot(): void
    {
        $this->bindIpGeolocation();
        $this->applyClientIpOverride();

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

        // Checkout matrix: an app-provided checkout step, switched on per test through
        // the shared settings table (`checkout_custom_step` = 1). Off by default, so no
        // other scenario's checkout grows a step.
        MollieBilling::checkoutStepsUsing(function (): array {
            if (WorkbenchSetting::get('checkout_custom_step') !== '1') {
                return [];
            }

            return [[
                'key' => 'terms',
                'label' => 'Terms',
                'headline' => 'Accept the workbench terms',
                'description' => 'An app-provided step, before the package steps.',
                'view' => 'wb::checkout-terms',
                'validate' => function ($component): void {
                    if (! ($component->customData['terms'] ?? false)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'customData.terms' => 'Please accept the workbench terms.',
                        ]);
                    }
                },
            ]];
        });

        // An app-provided header component, for `mollie-billing.header_components`.
        if (class_exists(\GraystackIT\MollieBilling\Tests\Support\HeaderProbeComponent::class)) {
            \Livewire\Livewire::component('wb-header-probe', \GraystackIT\MollieBilling\Tests\Support\HeaderProbeComponent::class);
        }

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
                // Operations matrix (tests/Browser/Matrix/Admin*): a plan that carries a
                // per-seat addon, so an invoice can hold every kind of line at once. Hidden,
                // so no plan list another scenario counts or clicks through grows by it.
                'ops-team' => [
                    'name' => 'Ops Team',
                    'description' => 'Seats plus a per-seat addon.',
                    'tier' => 4,
                    'hidden' => true,
                    'included_seats' => 2,
                    'feature_keys' => ['dashboard'],
                    'allowed_addons' => ['softdrinks', 'ops-crm'],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 4900,
                            'seat_price_net' => 700,
                            'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                        ],
                    ],
                ],
                // Change matrix (tests/Browser/Matrix/ChangeMatrixTest): a second plan that
                // allows the per-seat addon, so a plan change can keep it — without one, no
                // plan-change preview ever shows an `addon_seats` row. It includes more
                // seats than ops-team, so the addon's seat count moves with the plan.
                // Hidden like ops-team; a test shows both with
                // `serverConfig('mollie-billing-plans.plans.<code>.hidden', false)`.
                'ops-team-plus' => [
                    'name' => 'Ops Team Plus',
                    'description' => 'More seats, same per-seat addon.',
                    'tier' => 5,
                    'hidden' => true,
                    'included_seats' => 6,
                    'feature_keys' => ['dashboard'],
                    'allowed_addons' => ['softdrinks', 'ops-crm'],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 8900,
                            'seat_price_net' => 600,
                            'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                            'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                        ],
                    ],
                ],
                // Coupon matrix (tests/Browser/Matrix/Coupon*): a plan that costs nothing, so
                // "customer on the free plan" is a state a code can be typed in. Hidden for
                // the same reason as ops-team.
                'coupon-free' => [
                    'name' => 'Free Forever',
                    'description' => 'Costs nothing, never ends.',
                    'tier' => 0,
                    'hidden' => true,
                    'included_seats' => 1,
                    'feature_keys' => ['dashboard'],
                    'allowed_addons' => [],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 0,
                            'seat_price_net' => null,
                            'included_usages' => ['Tokens' => 10, 'SMS' => 0],
                        ],
                    ],
                ],
                // Change matrix (tests/Browser/Matrix/Change*): a free plan a paying customer
                // can downgrade to on the plan screen. Listed as hidden so that no other
                // matrix's plan cards, defaults or counts move; a test that wants it on the
                // screen shows it with
                // `serverConfig('mollie-billing-plans.plans.changes-free.hidden', false)`.
                'changes-free' => [
                    'name' => 'Free',
                    'description' => 'Costs nothing. One seat, no add-ons.',
                    'tier' => 0,
                    'hidden' => true,
                    'included_seats' => 1,
                    'feature_keys' => ['dashboard'],
                    'allowed_addons' => [],
                    'intervals' => [
                        'monthly' => [
                            'base_price_net' => 0,
                            'seat_price_net' => null,
                            'included_usages' => ['Tokens' => 10, 'SMS' => 0],
                        ],
                        'yearly' => [
                            'base_price_net' => 0,
                            'seat_price_net' => null,
                            'included_usages' => ['Tokens' => 120, 'SMS' => 0],
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
                // Base price plus a price per seat, none of them free — see `ops-team`.
                'ops-crm' => [
                    'name' => 'Ops CRM',
                    'feature_keys' => [],
                    'intervals' => [
                        'monthly' => ['price_net' => 1500, 'seat_price_net' => 300],
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
