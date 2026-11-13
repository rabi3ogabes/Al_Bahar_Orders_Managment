<?php

use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\FirebaseWorkerController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Middleware\SetAdminLocale;
use App\Livewire\Admin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// ── Payment & delivery provider endpoints (no session/CSRF; authenticity verified per provider) ──────────
Route::controller(PaymentWebhookController::class)->withoutMiddleware([VerifyCsrfToken::class])->group(function () {
    Route::post('webhooks/sadad', 'sadad')->middleware('throttle:webhooks')->name('webhooks.sadad');
    Route::post('webhooks/delivery/{provider}', 'delivery')->middleware('throttle:webhooks')->name('webhooks.delivery');
    Route::get('payments/sadad/{payment}/start', 'sadadStart')->name('payments.sadad.start');
    Route::match(['get', 'post'], 'payments/sadad/return', 'sadadReturn')->name('payments.sadad.return');
});

// ── Firebase web push service worker (served by Laravel so it carries the public Firebase settings saved in the dashboard) ──
// Two URLs, one script: the store and the Firebase SDK's default ask for the root path, the Flutter web app (base /mobile-web/) for its own.
// Plain script responses: no session, no cookies, so a visitor never gets a session cookie just because the browser checked for a new worker.
Route::withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, SetAdminLocale::class])->group(function () {
    Route::get('firebase-messaging-sw.js', [FirebaseWorkerController::class, 'show'])->name('push.worker');
    Route::get('mobile-web/firebase-messaging-sw.js', [FirebaseWorkerController::class, 'show'])->name('push.worker.mobile');
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
    Route::get('routes', 'routes')->middleware('throttle:30,1,partner-routes')->name('routes');
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
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/{path?}', [StorefrontController::class, 'show'])
    ->where('path', '^(?!(?:admin|partner|api|app|broadcasting|webhooks|payments|docs|build|store|mobile-web|storage|up|health)(?:/|$)).*$')
    ->name('storefront');
