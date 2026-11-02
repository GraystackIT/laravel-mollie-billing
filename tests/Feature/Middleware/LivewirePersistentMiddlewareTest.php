<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingPortal;
use GraystackIT\MollieBilling\Http\Middleware\PropagateRouteDefaults;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

it('re-applies the portal authorization on livewire update requests', function (): void {
    $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

    // AuthorizeBillingPortal is the only place MollieBilling::authorizes() is consulted
    // at the route level, and no portal component re-checks it internally — so without it
    // a logged-out or unauthorized visitor keeps driving the portal from a loaded tab.
    expect($middleware)->toContain(AuthorizeBillingPortal::class);
});

it('re-applies the route-default propagation on livewire update requests', function (): void {
    $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

    // Livewire rewrites REQUEST_URI to the original component URL and re-resolves that
    // route before running this list, so PropagateRouteDefaults does see the tenant
    // parameters there. Portal views render route(BillingRoute::checkout()) with no
    // explicit parameters and rely purely on URL::defaults, so on a multi-tenant mount
    // the first Livewire interaction threw UrlGenerationException — a 500 on first click.
    expect($middleware)->toContain(PropagateRouteDefaults::class);
});

it('re-applies the IP country gate on livewire update requests', function (): void {
    $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

    // Every portal, checkout and admin page is mounted behind BlockRestrictedCountries,
    // and a Livewire page never navigates again — so a gate that only ran on the initial
    // GET stayed open for as long as the tab did, and every component action (checkout
    // submit, seat change, OSS export) kept working from a country the policy denies.
    expect($middleware)->toContain(\GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries::class);
});
