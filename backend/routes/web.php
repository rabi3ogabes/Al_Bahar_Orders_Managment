<?php

use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Livewire\Admin;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// ── Payment & delivery provider endpoints (no session/CSRF; authenticity verified per provider) ──────────
Route::controller(PaymentWebhookController::class)->withoutMiddleware([VerifyCsrfToken::class])->group(function () {
    Route::post('webhooks/sadad', 'sadad')->middleware('throttle:webhooks')->name('webhooks.sadad');
    Route::post('webhooks/delivery/{provider}', 'delivery')->middleware('throttle:webhooks')->name('webhooks.delivery');
    Route::get('payments/sadad/{payment}/start', 'sadadStart')->name('payments.sadad.start');
    Route::match(['get', 'post'], 'payments/sadad/return', 'sadadReturn')->name('payments.sadad.return');
});

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
