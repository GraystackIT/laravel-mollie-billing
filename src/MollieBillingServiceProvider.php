<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling;

use GraystackIT\MollieBilling\Commands\OssExportCommand;
use GraystackIT\MollieBilling\Commands\PrepareOverageCommand;
use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Features\FeatureAccess;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingPortal;
use GraystackIT\MollieBilling\Http\Middleware\RequireActiveSubscription;
use GraystackIT\MollieBilling\Http\Middleware\RequirePlanFeature;
use GraystackIT\MollieBilling\IpGeolocation\IpGeolocationManager;
use GraystackIT\MollieBilling\Jobs\PrepareUsageOverageJob;
use GraystackIT\MollieBilling\Jobs\PruneProcessedWebhooksJob;
use GraystackIT\MollieBilling\Support\ConfigSubscriptionCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class MollieBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mollie-billing.php', 'mollie-billing');
        $this->mergeConfigFrom(__DIR__.'/../config/mollie-billing-plans.php', 'mollie-billing-plans');

        $this->app->singleton(MollieBilling::class);
        $this->app->alias(MollieBilling::class, 'mollie-billing');

        $this->app->singleton(SubscriptionCatalogInterface::class, ConfigSubscriptionCatalog::class);
        $this->app->singleton(FeatureAccess::class);

        $this->app->singleton(IpGeolocationManager::class, fn ($app) => new IpGeolocationManager($app));
    }

    public function boot(): void
    {
        $this->propagateMollieApiKey();
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerBlade();
        $this->registerLivewireComponents();
        $this->registerTranslations();
        $this->registerViews();
        $this->registerCommands();
        $this->registerScheduledJobs();
    }

    private function propagateMollieApiKey(): void
    {
        $key = config('mollie-billing.mollie_api_key');

        if (is_string($key) && $key !== '') {
            config(['mollie.key' => $key]);
        }
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

        $this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/billing'),
        ], 'billing-lang');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
            __DIR__.'/../database/migrations/add_billing_columns_to_billable_table.php.stub'
                => database_path('migrations/'.date('Y_m_d_His').'_add_billing_columns_to_billable_table.php'),
        ], 'mollie-billing-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('billing.portal', AuthorizeBillingPortal::class);
        $router->aliasMiddleware('billing.active', RequireActiveSubscription::class);
        $router->aliasMiddleware('billing.feature', RequirePlanFeature::class);
        $router->aliasMiddleware('billing.admin', AuthorizeBillingAdmin::class);

        if (class_exists(Livewire::class)) {
            Livewire::addPersistentMiddleware([
                RequireActiveSubscription::class,
            ]);
        }
    }

    private function registerBlade(): void
    {
        Blade::componentNamespace('GraystackIT\\MollieBilling\\View\\Components', 'billing');

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
        ]);
    }

    private function registerScheduledJobs(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PrepareUsageOverageJob::class)
                ->dailyAt(config('mollie-billing.overage_job_time', '02:00'))
                ->timezone('UTC');

            $schedule->job(PruneProcessedWebhooksJob::class)
                ->monthlyOn(1, '03:00')
                ->timezone('UTC');
        });
    }
}
