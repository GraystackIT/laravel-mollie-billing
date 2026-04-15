<?php

declare(strict_types=1);

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

