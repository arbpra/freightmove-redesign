<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Carrier;
use App\Http\Controllers\Api\V1\Shipper;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| Mounted under /api/v1 (see bootstrap/app.php). Structure follows
| docs/06-api-spec.md.
|
*/

Route::prefix('auth')->group(function () {
    // Throttled: these are the endpoints worth brute forcing.
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('shipper')->middleware('role:shipper')->group(function () {
        Route::get('overview', Shipper\OverviewController::class);
    });

    Route::prefix('carrier')->middleware('role:carrier')->group(function () {
        Route::get('overview', Carrier\OverviewController::class);
    });

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('overview', Admin\OverviewController::class);
    });
});
