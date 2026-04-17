<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Enums\SubscriptionStatus;
use GraystackIT\MollieBilling\Facades\MollieBilling;
use GraystackIT\MollieBilling\Http\Middleware\RequireActiveSubscription;
use GraystackIT\MollieBilling\Support\BillingRoute;
use GraystackIT\MollieBilling\Testing\TestBillable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(RequireActiveSubscription::class)
        ->get('/test-guarded', fn () => response('OK'));
});

it('redirects expired billable to the checkout route', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
        'subscription_status' => SubscriptionStatus::Expired,
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-guarded');

    $response->assertRedirect(route(BillingRoute::checkout()));
});

it('redirects past-due billable to the billing portal', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
        'subscription_status' => SubscriptionStatus::PastDue,
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-guarded');

    $response->assertRedirect(route(BillingRoute::name('index')));
});

it('allows access when billable has an active subscription', function () {
    $billable = TestBillable::create([
        'name' => 'Test Org',
        'email' => 'test@example.com',
        'subscription_status' => SubscriptionStatus::Active,
    ]);

    MollieBilling::resolveBillableUsing(fn () => $billable);

    $response = $this->get('/test-guarded');

    $response->assertOk();
});

it('allows access when no billable is resolved', function () {
    MollieBilling::resolveBillableUsing(fn () => null);

    $response = $this->get('/test-guarded');

    $response->assertOk();
});
