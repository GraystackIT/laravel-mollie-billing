<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The admin panel's way out.
 *
 * Staff administering billing arrive from somewhere and need to get back there, and where
 * "there" is differs per install — hence `BILLING_ADMIN_BACK_URL`, with the same two forms
 * the portal's `BILLING_DASHBOARD_URL` accepts. The resolution is shared rather than copied,
 * because two layouts expanding `route:` themselves is how the portal and the panel drift.
 */
it('passes a plain URL through untouched', function (): void {
    expect(BillingRoute::configuredUrl('https://app.test/admin'))->toBe('https://app.test/admin')
        ->and(BillingRoute::configuredUrl('/admin'))->toBe('/admin');
});

it('expands a named route', function (): void {
    Route::get('/staff-home', fn () => 'ok')->name('staff.home');
    // The name lookup is built when the routes are loaded; one added afterwards is invisible
    // to Route::has() until it is rebuilt.
    app('router')->getRoutes()->refreshNameLookups();

    expect(BillingRoute::configuredUrl('route:staff.home'))->toBe(url('/staff-home'));
});

it('hides the link rather than 500ing on a route that does not exist', function (): void {
    // Read from a layout, so throwing here would take down every page of the panel over a
    // config typo. `billing:check-config` reports it as an error instead — that is where a
    // typo should stop you, not in production.
    expect(BillingRoute::configuredUrl('route:no.such.route'))->toBeNull()
        ->and(BillingRoute::configuredUrl('route:'))->toBeNull();
});

it('treats an unset or blank value as no link', function (): void {
    expect(BillingRoute::configuredUrl(null))->toBeNull()
        ->and(BillingRoute::configuredUrl(''))->toBeNull()
        ->and(BillingRoute::configuredUrl('   '))->toBeNull();
});

it('falls back to the portal dashboard URL when no admin URL is configured', function (): void {
    // A single-app install configures one value; the panel does not need its own.
    config()->set('mollie-billing.admin_back_url', null);
    config()->set('mollie-billing.dashboard_url', 'https://app.test/home');

    $resolved = BillingRoute::configuredUrl(
        config('mollie-billing.admin_back_url') ?: config('mollie-billing.dashboard_url'),
    );

    expect($resolved)->toBe('https://app.test/home');
});

it('prefers the admin URL over the portal one when both are set', function (): void {
    // Staff administer from somewhere other than where customers land.
    config()->set('mollie-billing.admin_back_url', 'https://app.test/staff');
    config()->set('mollie-billing.dashboard_url', 'https://app.test/home');

    $resolved = BillingRoute::configuredUrl(
        config('mollie-billing.admin_back_url') ?: config('mollie-billing.dashboard_url'),
    );

    expect($resolved)->toBe('https://app.test/staff');
});

it('reports a misconfigured back URL through check-config', function (): void {
    config()->set('mollie-billing.admin_back_url', 'route:no.such.route');

    $this->artisan('billing:check-config')
        ->expectsOutputToContain('admin_back_url names route [no.such.route]')
        ->assertExitCode(1);
});
