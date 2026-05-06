<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingPortalController;
use Illuminate\Support\Facades\Route;

Route::get('billing/checkout', [BillingPortalController::class, 'checkout'])
    ->middleware('billing.country-resolved')
    ->name('billing.checkout');
