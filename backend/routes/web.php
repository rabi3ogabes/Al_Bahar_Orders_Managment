<?php

use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Livewire\Admin;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// ── Payment & delivery provider endpoints (no session/CSRF; authenticity verified per provider) ──────────
Route::controller(PaymentWebhookController::class)->withoutMiddleware([VerifyCsrfToken::class])->group(function () {
    Route::post('webhooks/sadad', 'sadad')->middleware('throttle:webhooks')->name('webhooks.sadad');
    Route::post('webhooks/delivery/{provider}', 'delivery')->middleware('throttle:webhooks')->name('webhooks.delivery');
    Route::get('payments/sadad/{payment}/start', 'sadadStart')->name('payments.sadad.start');
    Route::match(['get', 'post'], 'payments/sadad/return', 'sadadReturn')->name('payments.sadad.return');
});

// ── Partner statistics (secret link + password; see PartnerController) ──────────────────────────────────
// Each data route has its own rate-limit bucket (the prefix), so the fast live-map polling of one browser tab cannot lock out the others.
Route::prefix('partner/{token}')->name('partner.')->controller(PartnerController::class)->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('unlock', 'unlock')->name('unlock');
    Route::post('lock', 'lock')->name('lock');
    Route::get('data', 'day')->middleware('throttle:60,1,partner-day')->name('data');
    Route::get('orders/{id}', 'order')->middleware('throttle:90,1,partner-order')->name('order');
    Route::get('fleet', 'fleet')->middleware('throttle:60,1,partner-fleet')->name('fleet');
    Route::get('events', 'events')->middleware('throttle:40,1,partner-events')->name('events');
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

/*
|--------------------------------------------------------------------------
| Customer storefront (React single-page app built into public/store)
|--------------------------------------------------------------------------
| Every GET that is not the API, the admin, a provider endpoint or a static asset directory falls through
| to the storefront shell; the client router renders the page (or its own 404).
*/
Route::get('/{path?}', [StorefrontController::class, 'show'])
    ->where('path', '^(?!(?:admin|partner|api|app|broadcasting|webhooks|payments|docs|build|store|mobile-web|storage|up|health)(?:/|$)).*$')
    ->name('storefront');
