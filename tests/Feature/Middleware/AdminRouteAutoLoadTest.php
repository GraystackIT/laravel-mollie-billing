<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\MollieBilling;
use Illuminate\Support\Facades\Route;

// The README tells apps to call `MollieBilling::adminRoutes()` when they want the admin
// panel under their own middleware stack. Mounting it a second time does not replace the
// auto-loaded one: both carry the same URIs, the auto-loaded routes were registered first
// and therefore match first, so the app's stack never runs. The routes have to be
// switchable off for that instruction to mean anything.

it('does not auto-load the admin routes when the app mounts them itself', function (): void {
    config()->set('mollie-billing.routes.auto_load_admin', false);

    // Re-run route registration the way a fresh boot would.
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    (new \GraystackIT\MollieBilling\MollieBillingServiceProvider(app()))->boot();

    Route::getRoutes()->refreshNameLookups();
    expect(Route::getRoutes()->getByName('billing.admin.dashboard'))->toBeNull();

    Route::middleware(['web', 'app-own-guard'])->group(function (): void {
        MollieBilling::adminRoutes();
    });

    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('billing.admin.dashboard');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('app-own-guard');
});

it('auto-loads the admin routes by default', function (): void {
    expect(Route::getRoutes()->getByName('billing.admin.dashboard'))->not->toBeNull();
});
