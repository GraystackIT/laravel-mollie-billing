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
