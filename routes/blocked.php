<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Http\Controllers\BlockedCountryController;
use Illuminate\Support\Facades\Route;

Route::get('billing/blocked', BlockedCountryController::class)->name('billing.blocked');
