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
        // Always reachable (also with an open country mismatch) — the user
        // needs the dashboard to open the self-service correction modal,
        // billing-data to view the address, invoices to inspect history,
        // return as the post-Mollie-redirect landing, and invoice download.
        Route::get('/', [BillingPortalController::class, 'index'])->name('index');
        Route::get('invoices', [BillingPortalController::class, 'invoices'])->name('invoices');
        Route::get('invoices/{invoice}/download', InvoiceDownloadController::class)->name('invoice.download');
        Route::get('billing-data', [BillingPortalController::class, 'billingData'])->name('billing-data');
        Route::get('return', [BillingPortalController::class, 'return'])->name('return');

        // Booking-relevant routes are blocked while a country mismatch is
        // unresolved — booking with bad VAT data would just create more
        // invoices that need correction.
        Route::middleware('billing.country-resolved')->group(function (): void {
            Route::get('plan', [BillingPortalController::class, 'plan'])->name('plan');
            Route::get('addons', [BillingPortalController::class, 'addons'])->name('addons');
            Route::get('usage', [BillingPortalController::class, 'usage'])->name('usage');
            Route::get('seats', [BillingPortalController::class, 'seats'])->name('seats');
            Route::get('products', [BillingPortalController::class, 'products'])->name('products');
        });
    });
