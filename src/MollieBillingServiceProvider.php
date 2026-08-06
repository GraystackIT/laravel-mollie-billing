<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling;

use GraystackIT\MollieBilling\Commands\CheckConfigCommand;
use GraystackIT\MollieBilling\Commands\CleanupOrphanedBillablesCommand;
use GraystackIT\MollieBilling\Commands\OssExportCommand;
use GraystackIT\MollieBilling\Commands\PrepareOverageCommand;
use GraystackIT\MollieBilling\Commands\SimulateCommand;
use GraystackIT\MollieBilling\Commands\SyncPurchasedBalanceCommand;
use GraystackIT\MollieBilling\Commands\WebhookReplayCommand;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Events\MandateUpdated;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingPortal;
use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use GraystackIT\MollieBilling\Http\Middleware\PropagateRouteDefaults;
use GraystackIT\MollieBilling\Http\Middleware\RequireActiveSubscription;
use GraystackIT\MollieBilling\Http\Middleware\RequirePlanFeature;
use GraystackIT\MollieBilling\Http\Middleware\RequireResolvedCountryMismatch;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use GraystackIT\MollieBilling\Jobs\CleanupOrphanedBillablesJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingCountryCorrectionJob;
use GraystackIT\MollieBilling\Jobs\CleanupStalePendingProrataChangeJob;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\ProcessTrialLifecycleJob;
use GraystackIT\MollieBilling\Jobs\PruneBillingAuditJob;
use GraystackIT\MollieBilling\Jobs\PruneProcessedWebhooksJob;
use GraystackIT\MollieBilling\Jobs\SweepPendingRetriesJob;
use GraystackIT\MollieBilling\Listeners\RecordBillingAudit;
use GraystackIT\MollieBilling\Listeners\RevokePreviousMandate;
use GraystackIT\MollieBilling\Services\Billing\InvoiceNumberGenerator;
use GraystackIT\MollieBilling\Services\Billing\InvoiceService;
use GraystackIT\MollieBilling\Services\Billing\MollieSalesInvoiceService;
use GraystackIT\MollieBilling\Support\BillingAuditMap;
use GraystackIT\MollieBilling\Support\ConfigSubscriptionCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class MollieBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mollie-billing.php', 'mollie-billing');
        $this->mergeConfigFrom(__DIR__.'/../config/mollie-billing-plans.php', 'mollie-billing-plans');

        // Must happen here, before anything resolves a VatCalculator: mpociot snapshots
        // its whole config array in the constructor. Set later — as VatCalculationService
        // used to — the flag never reaches the instance, so SoapFaults keep being
        // swallowed into a bare `false` and "VIES is down" stays indistinguishable from
        // "this number is invalid".
        if (config('vat_calculator.forward_soap_faults') !== true) {
            config(['vat_calculator.forward_soap_faults' => true]);
        }

        $this->app->singleton(MollieBilling::class);
        $this->app->alias(MollieBilling::class, 'mollie-billing');

        $this->app->singleton(SubscriptionCatalogInterface::class, ConfigSubscriptionCatalog::class);
        $this->app->singleton(FeatureAccess::class);

        $this->app->singleton(IpGeolocationManager::class, fn ($app) => new IpGeolocationManager($app));

        $this->app->singleton(InvoiceNumberGenerator::class);
        $this->app->singleton(InvoiceService::class);
        $this->app->alias(InvoiceService::class, MollieSalesInvoiceService::class);
    }

    public function boot(): void
    {
        $this->propagateMollieApiKey();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerBlade();
        $this->registerLivewireComponents();
        $this->registerTranslations();
        $this->registerViews();
        $this->registerCommands();
        $this->registerScheduledJobs();
        $this->registerEventListeners();
    }

    private function registerEventListeners(): void
    {
        Event::listen(MandateUpdated::class, RevokePreviousMandate::class);

        // Audit trail. Listed explicitly from BillingAuditMap rather than via a
        // wildcard so the map stays the single source of truth for what is audited.
        Event::listen(array_keys(BillingAuditMap::all()), RecordBillingAudit::class);
    }

    private function propagateMollieApiKey(): void
    {
        $key = config('mollie-billing.mollie_api_key');

        if (is_string($key) && $key !== '') {
            config(['mollie.key' => $key]);
        }
    }

    /**
     * Load the package migrations. Deliberately outside `registerPublishing()`
     * (which is console-only): multi-tenant provisioning runs
     * `Artisan::call('migrate')` from an HTTP request, and a console-gated
     * registration would build the tenant schema without any billing table.
     */
    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/mollie-billing.php' => config_path('mollie-billing.php'),
            __DIR__.'/../config/mollie-billing-plans.php' => config_path('mollie-billing-plans.php'),
        ], 'mollie-billing-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/mollie-billing'),
        ], 'mollie-billing-views');

        // lang_path(), not resource_path('lang'): Laravel resolves the lang path
        // as `is_dir(resource_path('lang')) ? resources/lang : base_path('lang')`.
        // Publishing into resources/lang on an app whose translations live in
        // base_path('lang') would flip that resolution on the next boot and stop
        // every one of the app's own root-level lang files from loading.
        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/billing'),
        ], 'billing-lang');

        // Enumerated per file rather than as a directory so the billable-table
        // stub is not copied verbatim alongside its timestamped rename.
        $migrations = [];
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migration) {
            $migrations[$migration] = database_path('migrations/'.basename($migration));
        }
        $migrations[__DIR__.'/../database/migrations/add_billing_columns_to_billable_table.php.stub']
            = database_path('migrations/'.date('Y_m_d_His').'_add_billing_columns_to_billable_table.php');

        $this->publishes($migrations, 'mollie-billing-migrations');
    }

    private function registerRoutes(): void
    {
        // Portal/webhook routes are not auto-loaded — apps must mount them via
        // MollieBilling::dashboardRoutes() inside a route group that carries
        // their tenant prefix/middleware. BillingRoute auto-detects any
        // wrapping name prefix at runtime.
        //
        // Admin routes are tenant-agnostic, so they can be auto-loaded safely.
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');

        // The blocked-country page must be reachable without tenant context,
        // auth, or any of the package middleware (otherwise the IP block
        // middleware would loop the redirect target back through itself).
        $this->loadRoutesFrom(__DIR__.'/../routes/blocked.php');
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('billing.portal', AuthorizeBillingPortal::class);
        $router->aliasMiddleware('billing.active', RequireActiveSubscription::class);
        $router->aliasMiddleware('billing.country-resolved', RequireResolvedCountryMismatch::class);
        $router->aliasMiddleware('billing.feature', RequirePlanFeature::class);
        $router->aliasMiddleware('billing.admin', AuthorizeBillingAdmin::class);
        $router->aliasMiddleware('billing.ip-block', BlockRestrictedCountries::class);

        // Livewire re-applies only the middleware listed here on `/livewire/update`
        // requests — it rewrites REQUEST_URI to the original component URL and
        // re-resolves that route first, so a listed middleware sees the original route
        // and its parameters, not `/livewire/update`.
        //
        // AuthorizeBillingAdmin and AuthorizeBillingPortal must both be among them:
        // neither an admin nor a portal component re-checks authorization internally,
        // and these two middleware are the only place MollieBilling::authorizes() /
        // canAccessBillingAdmin() is consulted at all. Without them a revoked operator
        // keeps invoking admin actions from an already-loaded tab, and the `admin_locale`
        // pinning would not survive a single component interaction.
        //
        // PropagateRouteDefaults belongs here for the same reason: it is what repopulates
        // URL::defaults with the tenant parameters. Portal views render
        // `route(BillingRoute::checkout())` without explicit parameters and rely purely on
        // those defaults, so on a multi-tenant mount the first Livewire interaction threw
        // UrlGenerationException — a 500 on the first click.
        if (class_exists(Livewire::class)) {
            Livewire::addPersistentMiddleware([
                PropagateRouteDefaults::class,
                RequireActiveSubscription::class,
                AuthorizeBillingPortal::class,
                AuthorizeBillingAdmin::class,
            ]);
        }
    }

    private function registerBlade(): void
    {
        Blade::if('planFeature', function (string $feature): bool {
            $billable = MollieBilling::resolveBillable(request());

            return $billable ? $billable->hasPlanFeature($feature) : false;
        });
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        // Defer registration until the Livewire service provider has registered its
        // `livewire.finder` binding. In tests that don't boot Livewire, this never fires.
        $this->callAfterResolving('livewire.finder', function (): void {
            $this->doRegisterLivewireComponents();
        });
    }

    private function doRegisterLivewireComponents(): void
    {
        // Every view below this path is a Livewire 4 native single-file component
        // addressed through the `mollie-billing::` namespace — none of them is a
        // Volt component. Mounting the directory in Volt as well would register 34
        // additional *unprefixed* global names (`dashboard`, `checkout`, `invoices`,
        // `admin.dashboard`, …) that collide with the host app's own components.
        Livewire::addNamespace(
            namespace: 'mollie-billing',
            viewPath: __DIR__.'/../resources/views/livewire/billing',
        );
    }

    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'billing');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mollie-billing');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PrepareOverageCommand::class,
            OssExportCommand::class,
            SyncPurchasedBalanceCommand::class,
            CheckConfigCommand::class,
            CleanupOrphanedBillablesCommand::class,
            SimulateCommand::class,
            WebhookReplayCommand::class,
        ]);
    }

    private function registerScheduledJobs(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PrepareUsageOverageJob::class)
                ->dailyAt(config('mollie-billing.overage_job_time', '02:00'))
                ->timezone('UTC');

            $schedule->job(ProcessTrialLifecycleJob::class)
                ->dailyAt(config('mollie-billing.trial_lifecycle_job_time', '02:05'))
                ->timezone('UTC');

            $schedule->job(PruneProcessedWebhooksJob::class)
                ->monthlyOn(1, '03:00')
                ->timezone('UTC');

            if (config('mollie-billing.audit.retention_days') !== null) {
                $schedule->job(PruneBillingAuditJob::class)
                    ->monthlyOn(1, '03:30')
                    ->timezone('UTC');
            }

            $schedule->job(CleanupStalePendingCountryCorrectionJob::class)
                ->hourly()
                ->timezone('UTC');

            // Without this, a prorata charge whose webhook never arrives leaves
            // `pending_prorata_change` set forever and ValidateSubscriptionChange
            // rejects every later plan change for that billable.
            $schedule->job(CleanupStalePendingProrataChangeJob::class)
                ->dailyAt('03:45')
                ->timezone('UTC');

            // Safety net for the retry markers. UpdateSubscription and ProrataExecutor
            // do dispatch their `pending_subscription_patch` retry, but the coupon-expiry
            // patch, `pending_subscription_cancel` and every `pending_refund_retries`
            // line are written by services that only log "queued for retry" — this sweep
            // is the sole reader for those. It also restarts a chain that was lost with
            // its worker, whatever wrote the marker.
            $schedule->job(SweepPendingRetriesJob::class)
                ->hourly()
                ->timezone('UTC');

            if ((bool) config('mollie-billing.cleanup.enabled', true)) {
                $schedule->job(CleanupOrphanedBillablesJob::class)
                    ->cron((string) config('mollie-billing.cleanup.cron_expression', '*/15 * * * *'))
                    ->timezone('UTC');
            }
        });
    }
}
