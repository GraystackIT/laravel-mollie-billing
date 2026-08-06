<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingPortalController;
use GraystackIT\MollieBilling\Http\Controllers\InvoiceDownloadController;
use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use GraystackIT\MollieBilling\Http\Middleware\PropagateRouteDefaults;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')
    ->name('billing.')
    ->middleware([BlockRestrictedCountries::class, PropagateRouteDefaults::class])
    ->group(function (): void {
        // Deliberately outside `billing.portal`:
        //
        // `return` is the post-Mollie landing page. An anonymous signup's billable has
        // no owner yet, so `authorizes()` — which fails closed — would 403 every new
        // customer at the moment they come back from paying.
        //
        // `invoice.download` authorizes itself (see InvoiceDownloadController), and it
        // has to: it admits a billing admin who is *not* a member of the billable,
        // which the middleware would reject before the controller ever ran.
        Route::get('invoices/{invoice}/download', InvoiceDownloadController::class)->name('invoice.download');
        Route::get('return', [BillingPortalController::class, 'return'])->name('return');

        // Everything else is the tenant's own billing data. `billing.portal` was
        // aliased and registered as Livewire persistent middleware but never actually
        // applied to a route — and persistent middleware is only re-applied if the
        // original route carried it, so both were dead. Without this the initial page
        // load was unauthenticated and each component had to gate itself.
        Route::middleware('billing.portal')->group(function (): void {
            // Reachable even with an open country mismatch: the user needs the
            // dashboard to open the self-service correction modal, billing-data to
            // view the address, and invoices to inspect history.
            Route::get('/', [BillingPortalController::class, 'index'])->name('index');
            Route::get('invoices', [BillingPortalController::class, 'invoices'])->name('invoices');
            Route::get('billing-data', [BillingPortalController::class, 'billingData'])->name('billing-data');

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
    });
