<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingPortalController;
use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use Illuminate\Support\Facades\Route;

Route::get('billing/checkout', [BillingPortalController::class, 'checkout'])
    ->middleware([BlockRestrictedCountries::class, 'billing.country-resolved'])
    ->name('billing.checkout');
