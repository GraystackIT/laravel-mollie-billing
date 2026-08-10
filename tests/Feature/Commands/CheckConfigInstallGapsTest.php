<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\PlanChangeMode;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Route;

// The two misconfigurations a fresh consumer actually produces, both of which used to be
// invisible until a customer hit them.

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
});

it('errors when the Mollie webhook route was never mounted', function (): void {
    // Every payment carries `webhookUrl: route(BillingRoute::webhook())`, so an app that
    // mounted the portal and the checkout but not the webhook throws RouteNotFoundException
    // on its very first payment — a 500 in checkout with nothing naming the cause.
    BillingRoute::flush();

    $withoutWebhook = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route): bool => str_ends_with((string) $route->getName(), 'billing.webhook'));

    $collection = new Illuminate\Routing\RouteCollection;
    foreach ($withoutWebhook as $route) {
        $collection->add($route);
    }
    Route::setRoutes($collection);

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('webhook route is not mounted')
        ->assertFailed();
});

it('passes the webhook check once the route is mounted', function (): void {
    expect(Route::has(BillingRoute::webhook()))->toBeTrue();

    $this->artisan('billing:check-config')->run();

    // Whatever else the fixture config trips, it is not this.
    $this->artisan('billing:check-config')
        ->doesntExpectOutputToContain('webhook route is not mounted');
});

it('reports a plan_change_mode env value that is not a backing value, and names the one meant', function (): void {
    // The docs used to print the enum's case names. `PlanChangeMode::from()` in the config
    // file then threw a ValueError during `register()` — every request and every artisan
    // call dead, including this command. It degrades to the default now, so the typo is
    // reported here instead of taking the app down.
    putenv('BILLING_PLAN_CHANGE_MODE=EndOfPeriod');
    $_ENV['BILLING_PLAN_CHANGE_MODE'] = 'EndOfPeriod';
    $_SERVER['BILLING_PLAN_CHANGE_MODE'] = 'EndOfPeriod';

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('BILLING_PLAN_CHANGE_MODE [EndOfPeriod]')
        ->assertFailed();

    putenv('BILLING_PLAN_CHANGE_MODE');
    unset($_ENV['BILLING_PLAN_CHANGE_MODE'], $_SERVER['BILLING_PLAN_CHANGE_MODE']);
});

it('does not brick the app on an unparseable plan_change_mode', function (): void {
    // The whole point: whatever the value, requiring the config file must not throw.
    putenv('BILLING_PLAN_CHANGE_MODE=nonsense');
    $_ENV['BILLING_PLAN_CHANGE_MODE'] = 'nonsense';
    $_SERVER['BILLING_PLAN_CHANGE_MODE'] = 'nonsense';

    $config = require __DIR__.'/../../../config/mollie-billing.php';

    expect($config['plan_change_mode'])->toBe(PlanChangeMode::UserChoice);

    putenv('BILLING_PLAN_CHANGE_MODE');
    unset($_ENV['BILLING_PLAN_CHANGE_MODE'], $_SERVER['BILLING_PLAN_CHANGE_MODE']);
});

it('accepts a backing value whatever its casing', function (): void {
    putenv('BILLING_PLAN_CHANGE_MODE=End_Of_Period');
    $_ENV['BILLING_PLAN_CHANGE_MODE'] = 'End_Of_Period';
    $_SERVER['BILLING_PLAN_CHANGE_MODE'] = 'End_Of_Period';

    $config = require __DIR__.'/../../../config/mollie-billing.php';

    expect($config['plan_change_mode'])->toBe(PlanChangeMode::EndOfPeriod);

    putenv('BILLING_PLAN_CHANGE_MODE');
    unset($_ENV['BILLING_PLAN_CHANGE_MODE'], $_SERVER['BILLING_PLAN_CHANGE_MODE']);
});
