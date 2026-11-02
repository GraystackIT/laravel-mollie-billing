<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\CountryMismatchStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Http\Middleware\RequireResolvedCountryMismatch;
use GraystackIT\MollieBilling\Models\BillingCountryMismatch;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(RequireResolvedCountryMismatch::class)
        ->get('/test-mismatch-guarded', fn () => response('OK'));
});

it('redirects to the dashboard when an open country mismatch exists', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
    ]);
    BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Pending,
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-mismatch-guarded');

    $response->assertRedirect(route(BillingRoute::name('index')));
});

it('allows access when no mismatch is open', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-mismatch-guarded');

    $response->assertOk();
});

it('allows access when the mismatch was resolved', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
    ]);
    BillingCountryMismatch::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Resolved,
        'chosen_country' => 'DE',
        'resolved_at' => now(),
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-mismatch-guarded');

    $response->assertOk();
});

it('allows access when no billable is resolved', function () {
    MollieBilling::resolveBillableUsing(fn () => null);

    $response = $this->get('/test-mismatch-guarded');

    $response->assertOk();
});

it('does not reveal a query-selected stranger\'s open mismatch', function () {
    $foreign = TestBillable::create(['name' => 'Foreign', 'email' => 'foreign@example.test']);
    BillingCountryMismatch::create([
        'billable_type' => $foreign->getMorphClass(),
        'billable_id' => $foreign->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Pending,
    ]);

    // Checkout is mounted outside tenant context, so the app's resolver answers null and
    // the query-parameter guess decides. Redirect-or-not then told anyone who could name
    // a route key whether that tenant had an open VAT mismatch — the same oracle
    // BillingPortalController closed one layer further in.
    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn () => false);

    $route = Route::getRoutes()->getByName('billing.checkout');
    $request = \Illuminate\Http\Request::create('/billing/checkout?organization='.$foreign->getKey(), 'GET');
    $request->setRouteResolver(fn () => $route);

    $response = app(RequireResolvedCountryMismatch::class)->handle($request, fn () => response('OK'));

    expect($response->getStatusCode())->toBe(200);
});

it('still blocks the requester\'s own query-selected billable with an open mismatch', function () {
    $own = TestBillable::create(['name' => 'Own', 'email' => 'own@example.test']);
    BillingCountryMismatch::create([
        'billable_type' => $own->getMorphClass(),
        'billable_id' => $own->getKey(),
        'tax_country_user' => 'AT',
        'tax_country_payment' => 'DE',
        'tax_country_ip' => 'CH',
        'status' => CountryMismatchStatus::Pending,
    ]);

    MollieBilling::resolveBillableUsing(fn () => null);
    MollieBilling::authUsing(fn () => true);

    $route = Route::getRoutes()->getByName('billing.checkout');
    $request = \Illuminate\Http\Request::create('/billing/checkout?organization='.$own->getKey(), 'GET');
    $request->setRouteResolver(fn () => $route);

    $response = app(RequireResolvedCountryMismatch::class)->handle($request, fn () => response('OK'));

    expect($response->isRedirect())->toBeTrue();
});
