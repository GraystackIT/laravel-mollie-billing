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
use GraystackIT\MollieBilling\Models\Subscription as PackageSubscription;
use GraystackIT\MollieBilling\Support\ConfigSubscriptionCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\CashierMollie\Subscription as CashierSubscription;
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

        $this->app->bind(CashierSubscription::class, PackageSubscription::class);
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMiddleware();
        $this->registerBlade();
        $this->registerLivewireComponents();
        $this->registerTranslations();
        $this->registerViews();
        $this->registerCommands();
        $this->registerScheduledJobs();
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

        // Defer registration until the Livewire service provider has finished booting so
        // that the `livewire.finder` binding (and friends) is available.
        $this->app->booted(function (): void {
            if (! $this->app->resolved('livewire')) {
                return;
            }

            $this->doRegisterLivewireComponents();
        });
    }

    private function doRegisterLivewireComponents(): void
    {
        $map = [
            // Customer portal
            'billing::dashboard' => 'mollie-billing::livewire.billing.dashboard',
            'billing::checkout.plan-selection' => 'mollie-billing::livewire.billing.checkout.plan-selection',
            'billing::checkout.form' => 'mollie-billing::livewire.billing.checkout.form',
            'billing::plan-change' => 'mollie-billing::livewire.billing.plan-change',
            'billing::invoices' => 'mollie-billing::livewire.billing.invoices',
            'billing::usage-meter' => 'mollie-billing::livewire.billing.components.usage-meter',

            // Admin panel
            'billing::admin.dashboard' => 'mollie-billing::livewire.billing.admin.dashboard',
            'billing::admin.coupons.index' => 'mollie-billing::livewire.billing.admin.coupons.index',
            'billing::admin.coupons.create' => 'mollie-billing::livewire.billing.admin.coupons.create',
            'billing::admin.coupons.show' => 'mollie-billing::livewire.billing.admin.coupons.show',
            'billing::admin.billables.index' => 'mollie-billing::livewire.billing.admin.billables.index',
            'billing::admin.billables.show' => 'mollie-billing::livewire.billing.admin.billables.show',
            'billing::admin.billables.subscription-tab' => 'mollie-billing::livewire.billing.admin.billables.subscription-tab',
            'billing::admin.billables.invoices-tab' => 'mollie-billing::livewire.billing.admin.billables.invoices-tab',
            'billing::admin.billables.wallet-tab' => 'mollie-billing::livewire.billing.admin.billables.wallet-tab',
            'billing::admin.refunds.index' => 'mollie-billing::livewire.billing.admin.refunds.index',
            'billing::admin.refunds.refund-modal' => 'mollie-billing::livewire.billing.admin.refunds.refund-modal',
            'billing::admin.grants.issue' => 'mollie-billing::livewire.billing.admin.grants.issue',
            'billing::admin.scheduled_changes.index' => 'mollie-billing::livewire.billing.admin.scheduled-changes.index',
            'billing::admin.past_due.index' => 'mollie-billing::livewire.billing.admin.past-due.index',
            'billing::admin.mismatches.index' => 'mollie-billing::livewire.billing.admin.mismatches.index',
            'billing::admin.oss.index' => 'mollie-billing::livewire.billing.admin.oss.index',
            'billing::admin.bulk.index' => 'mollie-billing::livewire.billing.admin.bulk.index',
        ];

        foreach ($map as $alias => $view) {
            Livewire::component($alias, $view);
        }
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
