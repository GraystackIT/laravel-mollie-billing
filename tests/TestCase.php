<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests;

use Bavix\Wallet\WalletServiceProvider;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\MollieBillingServiceProvider;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mpociot\VatCalculator\VatCalculatorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            VatCalculatorServiceProvider::class,
            WalletServiceProvider::class,
            MollieBillingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mollie-billing.billable_model', TestBillable::class);
        $app['config']->set('mollie-billing.billable_key_type', 'int');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        MollieBilling::dashboardRoutes();
        MollieBilling::webhookRoutes();
        MollieBilling::promotionRoutes();
        MollieBilling::checkoutRoutes();
    }

    protected function tearDown(): void
    {
        BillingRoute::flush();
        parent::tearDown();
    }
}
