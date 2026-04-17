<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\MollieWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('billing/webhook', MollieWebhookController::class)
    ->name('billing.webhook');
