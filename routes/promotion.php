<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::get('promotion/{token}', PromotionController::class)
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('billing.promotion');
