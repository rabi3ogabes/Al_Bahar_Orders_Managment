<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1 — /api/v1
|--------------------------------------------------------------------------
| Customer endpoints use Sanctum tokens with the "customer" ability; staff endpoints live under
| /api/v1/admin and require the "staff" ability plus action-based, branch-scoped permissions.
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    Route::get('health', V1\HealthController::class)->name('health')->withoutMiddleware('throttle:api');

    // ── Authentication ────────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->controller(V1\Auth\AuthController::class)->group(function () {
        Route::post('otp/request', 'requestOtp')->middleware('throttle:otp')->name('otp.request');
        Route::post('otp/verify', 'verifyOtp')->middleware('throttle:login')->name('otp.verify');
        Route::post('register', 'register')->middleware('throttle:login')->name('register');
        Route::post('login', 'login')->middleware('throttle:login')->name('login');
        Route::post('password/reset', 'resetPassword')->middleware('throttle:password-reset')->name('password.reset');
        Route::post('refresh', 'refresh')->middleware('throttle:login')->name('refresh');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', 'logout')->name('logout');
            Route::post('logout-all', 'logoutAll')->name('logout-all');
        });
    });

    // ── Customer account ──────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'customer'])->prefix('me')->name('me.')->controller(V1\Customer\ProfileController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'update')->name('update');
        Route::put('password', 'changePassword')->name('password');
        Route::post('devices', 'registerDevice')->name('devices.store');
        Route::delete('devices', 'unregisterDevice')->name('devices.destroy');
        Route::get('sessions', 'sessions')->name('sessions');
        Route::delete('sessions/{id}', 'revokeSession')->name('sessions.destroy');
        Route::post('deletion-request', 'requestDeletion')->name('deletion.store');
        Route::delete('deletion-request', 'cancelDeletion')->name('deletion.destroy');
    });

    // ── Staff / admin API ─────────────────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('auth/login', [V1\Admin\StaffAuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

        Route::middleware(['auth:sanctum', 'staff'])->group(function () {
            Route::get('me', [V1\Admin\StaffAuthController::class, 'me'])->name('me');
            require __DIR__.'/api_admin.php';
        });
    });

    require __DIR__.'/api_public.php';
});
