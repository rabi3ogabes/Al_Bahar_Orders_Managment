<?php

use App\Http\Controllers\Admin\SessionController;
use App\Livewire\Admin;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
|--------------------------------------------------------------------------
| Laravel admin dashboard (Blade + Livewire) — staff only
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('locale/{locale}', [SessionController::class, 'locale'])->name('locale');

    Route::middleware('guest')->group(function () {
        Route::get('login', Admin\Auth\Login::class)->name('login');
    });
    Route::middleware('auth')->group(function () {
        Route::get('two-factor', Admin\Auth\TwoFactorChallenge::class)->name('two-factor.challenge');
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
    });

    Route::middleware(['auth', 'staff'])->group(function () {
        Route::get('/', Admin\Dashboard::class)->middleware('permission:dashboard.view,deliveries.view')->name('dashboard');
        Route::get('profile', Admin\Profile::class)->name('profile');

        require __DIR__.'/admin.php';
    });
});
