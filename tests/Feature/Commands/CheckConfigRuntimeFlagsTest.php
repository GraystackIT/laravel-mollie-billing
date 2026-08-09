<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Route;

// Two settings that live outside `mollie-billing.php` but decide whether this package
// works: bavix's wallet lock, and whether the admin routes were actually mounted after
// the auto-load was switched off. Neither breaks anything at boot, so nothing tells the
// operator — which is exactly what `billing:check-config` is for.

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    config()->set('mollie-billing.billable_key_type', 'int');
    config()->set('mollie-billing.plan_change_mode', PlanChangeMode::UserChoice);
    config()->set('mollie-billing.currency', 'EUR');
    config()->set('mollie-billing.invoices.disk', 'local');
    config()->set('mollie-billing.invoices.serial_number.format', 'PP-YYCCCCCC');
    config()->set('mollie-billing.invoices.serial_number.prefix', [
        'invoice' => 'IN', 'credit_note' => 'CR', 'one_time_order' => 'OT',
    ]);
    config()->set('mollie-billing.invoices.seller', [
        'company' => 'ACME GmbH', 'name' => 'Jane Doe', 'email' => 'billing@acme.test',
        'phone' => '+43 123', 'tax_number' => 'ATU12345678',
        'address' => ['street' => 'Main 1', 'city' => 'Vienna', 'postal_code' => '1010', 'state' => null, 'country' => 'AT'],
    ]);
    config()->set('mollie-billing.checkout_countries', ['regions' => ['EU'], 'include' => [], 'exclude' => []]);
    config()->set('mollie-billing.ip_geolocation', ['driver' => 'null', 'drivers' => ['null' => []]]);
    config()->set('mollie-billing.billing_timezone', 'UTC');
    config()->set('mollie-billing.overage_job_time', '02:00');
    config()->set('mollie-billing.usage_threshold_percent', 80);

    config()->set('mollie-billing-plans.plans', [
        'pro' => [
            'name' => 'Pro', 'tier' => 2, 'included_seats' => 1,
            'feature_keys' => ['dashboard'], 'allowed_addons' => [],
            'intervals' => ['monthly' => [
                'base_price_net' => 1000, 'seat_price_net' => null,
                'included_usages' => ['Tokens' => 100], 'usage_overage_prices' => ['Tokens' => 5],
            ]],
        ],
    ]);
    config()->set('mollie-billing-plans.features', ['dashboard' => ['name' => 'Dashboard', 'description' => null]]);
    config()->set('mollie-billing-plans.addons', []);
    config()->set('mollie-billing-plans.product_groups', []);
    config()->set('mollie-billing-plans.products', []);
    config()->set('mollie-billing-plans.usage_types', ['Tokens' => ['rollover' => false]]);
});

it('warns when the wallet lock cannot serialise across processes', function (): void {
    // bavix defaults `wallet.lock.driver` to `array` — a per-process store, so the lock it
    // takes around a balance update protects nothing between a web request recording usage
    // and the queue worker charging the overage. The balance both of them read is the one
    // this package bills from.
    config()->set('wallet.lock.driver', 'array');
    config()->set('queue.default', 'redis');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('wallet.lock.driver')
        ->assertExitCode(0);
});

it('accepts a wallet lock that does serialise', function (): void {
    config()->set('wallet.lock.driver', 'redis');
    config()->set('queue.default', 'redis');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('wallet.lock.driver')
        ->assertExitCode(0);
});

it('warns when the admin routes are neither auto-loaded nor mounted', function (): void {
    // Switching the auto-load off is how an app mounts them under its own middleware. If
    // it then never calls `MollieBilling::adminRoutes()` there simply is no admin panel,
    // and nothing says so — the URLs just 404.
    config()->set('mollie-billing.routes.auto_load_admin', false);

    // Rebuild the route table the way a boot with the flag off produces it: everything
    // the app mounts itself, and no admin routes.
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    \GraystackIT\MollieBilling\MollieBilling::dashboardRoutes();
    \GraystackIT\MollieBilling\MollieBilling::webhookRoutes();
    Route::getRoutes()->refreshNameLookups();

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('auto_load_admin')
        ->assertExitCode(0);
});

it('warns when the cache store cannot hold a lock across processes', function (): void {
    // The package's plan-change, refund and seat-delta guards are all Cache::lock(). On the
    // array store that lock is one process's memory — a second worker takes it too, and
    // both changes proceed against the state each of them validated.
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'redis');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('billing:subscription-change')
        ->assertExitCode(0);
});

it('warns even on a sync queue when the cache cannot lock at all', function (): void {
    // `null` is worse than `array`: it grants every lock, so the guards do nothing even
    // within one process.
    config()->set('cache.default', 'null');
    config()->set('queue.default', 'sync');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('null driver grants every lock')
        ->assertExitCode(0);
});

it('accepts a lock-capable shared cache store', function (): void {
    config()->set('cache.default', 'file');
    config()->set('queue.default', 'redis');

    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('billing:subscription-change')
        ->assertExitCode(0);
});

it('warns when a usage type is sold on one interval of a plan but not the other', function (): void {
    // Nothing is wrong until a customer switches interval, and then the quota resolves to
    // 0 and — with no overage price on that side — the hard cap turns their next call into
    // an exception. Same plan, same subscription, metered feature gone.
    config()->set('mollie-billing-plans.plans.pro.intervals.yearly', [
        'base_price_net' => 10000,
        'seat_price_net' => null,
        'included_usages' => [],
        'usage_overage_prices' => [],
    ]);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('Tokens')
        ->assertExitCode(0);
});
