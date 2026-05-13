<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingAdminController;
use GraystackIT\MollieBilling\Http\Controllers\InvoiceDownloadController;
use GraystackIT\MollieBilling\Http\Controllers\OssExportDownloadController;
use GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin;
use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', BlockRestrictedCountries::class, AuthorizeBillingAdmin::class])
    ->prefix('billing/admin')->name('billing.admin.')->group(function (): void {
        Route::get('/', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'dashboard')->name('dashboard');

        // Admin-side invoice PDF download. Lives outside the tenant prefix so
        // staff can fetch any billable's PDF without being a member of that
        // tenant. The controller honours both tenant and admin auth paths.
        Route::get('invoices/{invoice}/download', InvoiceDownloadController::class)
            ->name('invoice.download');
        Route::get('coupons', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'coupons.index')->name('coupons.index');
        Route::get('coupons/create', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'coupons.create')->name('coupons.create');
        Route::get('coupons/{coupon}', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'coupons.show')->name('coupons.show');
        Route::get('billables', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'billables.index')->name('billables.index');
        Route::get('billables/{billable}', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'billables.show')->name('billables.show');
        Route::get('billables/{billable}/grant', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'grants.issue')->name('grants.create');
        Route::get('scheduled-changes', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'scheduled-changes.index')->name('scheduled_changes.index');
        Route::get('past-due', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'past-due.index')->name('past_due.index');
        Route::get('mismatches', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'mismatches.index')->name('mismatches.index');
        Route::get('refunds', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'refunds.index')->name('refunds.index');
        Route::get('oss', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'oss.index')->name('oss.index');
        Route::get('oss/exports/{export}/download', OssExportDownloadController::class)
            ->name('oss.download');
        Route::get('bulk', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'bulk.index')->name('bulk.index');
    });
