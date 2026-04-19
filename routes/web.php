<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingPortalController;
use GraystackIT\MollieBilling\Http\Controllers\InvoiceDownloadController;
use GraystackIT\MollieBilling\Http\Middleware\PropagateRouteDefaults;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')
    ->name('billing.')
    ->middleware(PropagateRouteDefaults::class)
    ->group(function (): void {
        Route::get('/', [BillingPortalController::class, 'index'])->name('index');
        Route::get('plan', [BillingPortalController::class, 'plan'])->name('plan');
        Route::get('invoices', [BillingPortalController::class, 'invoices'])->name('invoices');
        Route::get('invoices/{invoice}/download', InvoiceDownloadController::class)->name('invoice.download');
        Route::get('addons', [BillingPortalController::class, 'addons'])->name('addons');
        Route::get('seats', [BillingPortalController::class, 'seats'])->name('seats');
        Route::get('payment-method', [BillingPortalController::class, 'paymentMethod'])->name('payment-method');
        Route::get('return', [BillingPortalController::class, 'return'])->name('return');
    });
