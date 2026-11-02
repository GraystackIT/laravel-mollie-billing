<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('mollie-billing.billable_model', TestBillable::class);
    MollieBilling::resolveBillableUsing(fn (Request $r) => null);
});

afterEach(function (): void {
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty('resolveBillableCallback');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

it('still resolves a billable from a query parameter on the checkout route', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $request = Request::create('/billing/checkout?organization='.$billable->getKey(), 'GET');

    expect(MollieBilling::resolveBillable($request)?->getKey())->toBe($billable->getKey());
});

it('ignores query parameters on an application route', function (): void {
    /** @var TestBillable $foreign */
    $foreign = TestBillable::create(['name' => 'Foreign', 'email' => 'foreign@example.test']);

    // `billing.feature` / `billing.active` are applied by the app to app routes, and
    // both decide access against whatever resolveBillable() returns. Scanning arbitrary
    // query parameters there let `?x=<foreign-slug>` satisfy a feature gate.
    Route::get('/app/reports', fn () => 'ok')->name('app.reports');
    Route::getRoutes()->refreshNameLookups();

    $route = Route::getRoutes()->getByName('app.reports');
    expect($route)->not->toBeNull();
    $request = Request::create('/app/reports?x='.$foreign->getKey(), 'GET');
    $request->setRouteResolver(fn () => $route);

    expect(MollieBilling::resolveBillable($request))->toBeNull();
});

it('does not gate a visitor against an unrelated billable named by a tracking parameter', function (): void {
    TestBillable::create(['name' => 'spring', 'email' => 'spring@example.test']);

    Route::get('/app/pricing', fn () => 'ok')->name('app.pricing');
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('app.pricing');
    expect($route)->not->toBeNull();

    $request = Request::create('/app/pricing?utm_campaign=spring', 'GET');
    $request->setRouteResolver(fn () => $route);

    expect(MollieBilling::resolveBillable($request))->toBeNull();
});

it('still honours a route-bound billable on any route', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Bound', 'email' => 'bound@example.test']);

    Route::get('/app/{organization}', fn () => 'ok')->name('app.show');
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('app.show');

    $request = Request::create('/app/'.$billable->getKey(), 'GET');
    $route->bind($request);
    $route->setParameter('organization', $billable);
    $request->setRouteResolver(fn () => $route);

    // A route-bound model came from the router's own binding, not from arbitrary
    // request input, so it stays trusted.
    expect(MollieBilling::resolveBillable($request)?->getKey())->toBe($billable->getKey());
});

it('exposes routes() as the README documents it', function (): void {
    expect(method_exists(\GraystackIT\MollieBilling\MollieBilling::class, 'routes'))->toBeTrue();

    Route::name('probe.')->group(function (): void {
        MollieBilling::routes();
    });
    Route::getRoutes()->refreshNameLookups();

    // routes() mounts the portal route file — the same set as dashboardRoutes().
    expect(Route::getRoutes()->getByName('probe.billing.index'))->not->toBeNull();
    expect(Route::getRoutes()->getByName('probe.billing.invoices'))->not->toBeNull();
});

it('returns a fresh fake for each application instance', function (): void {
    $first = MollieBilling::fake();

    expect(MollieBilling::fake())->toBe($first);

    // A stale fake bound to a torn-down container is what the second test in a run
    // used to receive.
    $prop = (new ReflectionClass(\GraystackIT\MollieBilling\MollieBilling::class))->getProperty('fakeApp');
    $prop->setAccessible(true);
    $prop->setValue(null, new stdClass);

    expect(MollieBilling::fake())->not->toBe($first);
});

it('ignores query parameters on an application route whose name merely contains billing.', function (): void {
    /** @var TestBillable $foreign */
    $foreign = TestBillable::create(['name' => 'Foreign', 'email' => 'foreign@example.test']);

    // The package-route test used to be `str_contains($name, 'billing.')`, so an app's own
    // `settings.billing.history` — exactly the kind of page that carries `billing.feature`
    // — counted as a package route and `?x=<foreign-key>` selected a stranger again.
    Route::get('/settings/billing/history', fn () => 'ok')->name('settings.billing.history');
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('settings.billing.history');

    $request = Request::create('/settings/billing/history?x='.$foreign->getKey(), 'GET');
    $request->setRouteResolver(fn () => $route);

    expect(MollieBilling::resolveBillable($request))->toBeNull();
});

it('still resolves a billable from a query parameter on the bound checkout route', function (): void {
    /** @var TestBillable $billable */
    $billable = TestBillable::create(['name' => 'Acme', 'email' => 'acme@example.test']);

    $route = Route::getRoutes()->getByName('billing.checkout');
    $request = Request::create('/billing/checkout?organization='.$billable->getKey(), 'GET');
    $request->setRouteResolver(fn () => $route);

    expect(MollieBilling::resolveBillable($request)?->getKey())->toBe($billable->getKey());
});
