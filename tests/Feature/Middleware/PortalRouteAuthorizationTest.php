<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;

/**
 * `billing.portal` was aliased and registered as Livewire persistent middleware but
 * never applied to a route. Persistent middleware is only re-applied if the original
 * route carried it, so both registrations were dead and the initial portal page load
 * was unauthorized — every component had to gate itself, and any that forgot leaked
 * another tenant's billing data.
 */
beforeEach(function (): void {
    // The portal layouts pull a Vite bundle the package's test app has never built.
    // Without this the granted cases fail while rendering, which would hide whether
    // the middleware let them through at all.
    $this->withoutVite();
});

function portalRouteBillable(): TestBillable
{
    /** @var TestBillable $b */
    $b = TestBillable::create([
        'name' => 'Portal Route',
        'email' => uniqid('portal-route-').'@x.test',
        'billing_country' => 'AT',
    ]);

    MollieBilling::resolveBillableUsing(fn () => $b);

    return $b;
}

it('refuses a portal page when the app denies access to the billable', function (string $route): void {
    portalRouteBillable();
    MollieBilling::authUsing(fn (): bool => false);

    $this->get(route(BillingRoute::name($route)))->assertForbidden();
})->with(['index', 'invoices', 'billing-data', 'plan', 'addons', 'usage', 'seats', 'products']);

it('lets a granted request past the authorization gate', function (string $route): void {
    // Asserted as "not 403" rather than "200": with no active subscription the
    // dashboard and the plan page legitimately redirect to checkout, which is a
    // different middleware's decision. What this pins is that `billing.portal` is not
    // the thing standing in the way.
    portalRouteBillable();
    MollieBilling::authUsing(fn (): bool => true);

    $status = $this->get(route(BillingRoute::name($route)))->status();

    expect($status)->not->toBe(403);
})->with(['index', 'invoices', 'billing-data', 'plan', 'addons', 'usage', 'seats', 'products']);

it('refuses a portal page when no billable resolves at all', function (): void {
    // authorizes() fails closed, and resolveBillable() returning null is the shape a
    // logged-out or tenant-less request takes.
    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn (): bool => true);

    $this->get(route(BillingRoute::name('index')))->assertForbidden();
});

it('leaves the post-Mollie return page reachable without authorization', function (): void {
    // An anonymous signup's billable has no owner yet, so gating this would 403 every
    // new customer at the moment they come back from paying.
    portalRouteBillable();
    MollieBilling::authUsing(fn (): bool => false);

    $this->get(route(BillingRoute::name('return')))->assertOk();
});
