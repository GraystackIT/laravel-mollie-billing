<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\BillingRoute;

it('registers the billing-data portal route', function (): void {
    expect(\Route::has(BillingRoute::name('billing-data')))->toBeTrue();
});

it('no longer registers the legacy payment-method portal route', function (): void {
    expect(\Route::has(BillingRoute::name('payment-method')))->toBeFalse();
});

it('exposes the billing-data route under the /billing/billing-data path', function (): void {
    $url = route(BillingRoute::name('billing-data'));

    expect($url)->toEndWith('/billing/billing-data');
});
