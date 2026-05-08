<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\PromotionController;
use GraystackIT\MollieBilling\Http\Middleware\BlockRestrictedCountries;
use Illuminate\Support\Facades\Route;

Route::get('promotion/{token}', PromotionController::class)
    ->where('token', '[A-Za-z0-9._-]+')
    ->middleware(BlockRestrictedCountries::class)
    ->name('billing.promotion');
