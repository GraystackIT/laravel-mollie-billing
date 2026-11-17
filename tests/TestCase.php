<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests;

use Bavix\Wallet\WalletServiceProvider;
use GraystackIT\MollieBilling\MollieBilling;
use GraystackIT\MollieBilling\MollieBillingServiceProvider;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Support\MollieCall;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mpociot\VatCalculator\VatCalculatorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            VatCalculatorServiceProvider::class,
            WalletServiceProvider::class,
            \Elegantly\Invoices\InvoiceServiceProvider::class,
            \Spatie\Activitylog\ActivitylogServiceProvider::class,
            MollieBillingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
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

    /**
     * `RefreshDatabase` wraps every case in a transaction of its own, so "nothing held"
     * is that depth, not 0. Pinning it here is what lets {@see MollieCall} enforce the
     * no-Mollie-inside-a-transaction rule during the whole suite instead of only where a
     * test happens to assert `DB::transactionLevel()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        MollieCall::useCurrentTransactionLevelAsBaseline();

        // Refunds and chargebacks are read through MollieCall (MolliePaymentCredits). Many
        // tests hand the webhook a payment stub that lists them itself; the double answers
        // from the stub after the same guard a real request passes, and sends a real
        // request for an SDK payment. See tests/Support/StubAwarePaymentCredits.php.
        $this->app->bind(
            \GraystackIT\MollieBilling\Support\MolliePaymentCredits::class,
            \GraystackIT\MollieBilling\Tests\Support\StubAwarePaymentCredits::class,
        );
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
        MollieCall::resetBaselineTransactionLevel();
        BillingRoute::flush();
        MollieBilling::flushNotificationMap();
        MollieBilling::flushCallbacks();
        MollieBilling::resetFake();
        parent::tearDown();
    }
}
