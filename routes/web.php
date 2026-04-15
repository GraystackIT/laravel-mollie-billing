<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BillingAdminController;
use GraystackIT\MollieBilling\Http\Controllers\BillingPortalController;
use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use GraystackIT\MollieBilling\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

// Mollie calls this from its own servers without a CSRF token. We skip the framework's
// VerifyCsrfToken middleware when present. The class name differs across Laravel versions
// (L10: Illuminate\Foundation\Http\Middleware\VerifyCsrfToken, L11+ moved it). Resolve it
// lazily so the route file works on every supported version.
$csrfMiddleware = class_exists(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
    ? \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class
    : null;

$webhookRoute = Route::post('billing/webhook', MollieWebhookController::class)
    ->name('billing.webhook');

if ($csrfMiddleware !== null) {
    $webhookRoute->withoutMiddleware($csrfMiddleware);
}

Route::get('promotion/{token}', PromotionController::class)
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('billing.promotion');

Route::prefix('billing')->name('billing.')->group(function (): void {
    Route::get('/', [BillingPortalController::class, 'index'])->name('index');
    Route::get('checkout', [BillingPortalController::class, 'checkout'])->name('checkout');
    Route::get('plan', [BillingPortalController::class, 'plan'])->name('plan');
    Route::get('invoices', [BillingPortalController::class, 'invoices'])->name('invoices');
    Route::get('return', [BillingPortalController::class, 'return'])->name('return');
});

Route::middleware(\GraystackIT\MollieBilling\Http\Middleware\AuthorizeBillingAdmin::class)
    ->prefix('billing/admin')->name('billing.admin.')->group(function (): void {
        Route::get('/', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'dashboard')->name('dashboard');
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
            ->defaults('screen', 'grants.create')->name('grants.create');
        Route::get('scheduled-changes', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'scheduled_changes.index')->name('scheduled_changes.index');
        Route::get('past-due', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'past_due.index')->name('past_due.index');
        Route::get('mismatches', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'mismatches.index')->name('mismatches.index');
        Route::get('refunds', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'refunds.index')->name('refunds.index');
        Route::get('oss', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'oss.index')->name('oss.index');
        Route::get('bulk', [BillingAdminController::class, 'show'])
            ->defaults('screen', 'bulk.index')->name('bulk.index');
    });
