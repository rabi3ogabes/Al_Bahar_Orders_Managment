<?php

// Customer-facing catalog, branches, cart, checkout, orders, auctions (prefix /api/v1).

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

Route::controller(V1\Branches\BranchController::class)->group(function () {
    Route::get('branches', 'index')->name('branches.index');
    Route::get('branches/nearby', 'nearby')->name('branches.nearby');
    Route::get('branches/{id}', 'show')->whereUuid('id')->name('branches.show');
    Route::get('branches/{id}/hours', 'hours')->whereUuid('id')->name('branches.hours');
    Route::get('branches/{id}/pickup-slots', 'pickupSlots')->whereUuid('id')->name('branches.pickup-slots');
    Route::get('branches/{id}/delivery-slots', 'deliverySlots')->whereUuid('id')->name('branches.delivery-slots');
    Route::get('delivery-zones', 'zones')->name('delivery-zones.index');
});

Route::post('integrations/n8n/delivery', [V1\Integrations\N8nController::class, 'delivery'])->middleware('throttle:webhooks')->name('integrations.n8n.delivery');
Route::get('theme', [V1\Cms\ThemeController::class, 'show'])->name('theme');
Route::get('realtime-config', [V1\RealtimeConfigController::class, 'show'])->name('realtime-config');
Route::get('map-config', [V1\MapConfigController::class, 'show'])->name('map-config');
Route::get('push-config', [V1\PushConfigController::class, 'show'])->name('push-config');
// A phone app says why push does not work on it (before sign-in too): strict, small and rate limited, see PushDiagnosticsController.
Route::post('push-diagnostics', [V1\PushDiagnosticsController::class, 'store'])->middleware('throttle:push-diagnostics')->name('push-diagnostics');
Route::get('app-config', [V1\AppConfigController::class, 'show'])->name('app-config');

Route::controller(V1\Catalog\CatalogController::class)->group(function () {
    Route::get('categories', 'categories')->name('categories.index');
    Route::get('categories/{id}', 'category')->name('categories.show');
    Route::get('brands', 'brands')->name('brands.index');
    Route::get('rooms', 'rooms')->name('rooms.index');
    Route::get('products', 'products')->name('products.index');
    Route::get('products/search', 'search')->name('products.search');
    Route::get('search/suggest', 'suggest')->middleware('throttle:120,1')->name('search.suggest');
    Route::get('products/{id}/availability', 'availability')->whereUuid('id')->name('products.availability');
    Route::get('products/{idOrSlug}', 'product')->name('products.show');
    Route::get('branches/{branchId}/products', 'branchProducts')->whereUuid('branchId')->name('branches.products');
    // "Scan to add": any scanned string (barcode, SKU, store link, deep link, slug). `.+` lets a whole link through as one parameter.
    Route::get('products/by-code/{code}', 'byCode')->where('code', '.+')->middleware('throttle:product-scan')->name('products.by-code');
});

// ── Delivery / pickup date promise (public: a guest asks too; a signed-in caller's token only adds their own saved address) ─────
Route::get('products/{id}/delivery-promise', [V1\Commerce\PromiseController::class, 'product'])->whereUuid('id')->middleware('throttle:delivery-promise')->name('products.delivery-promise');
Route::post('cart/promise', [V1\Commerce\PromiseController::class, 'basket'])->middleware('throttle:delivery-promise')->name('cart.promise');

// ── Customer commerce (auth: customer) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'customer'])->group(function () {
    Route::controller(V1\Commerce\AddressController::class)->prefix('me/addresses')->name('addresses.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{id}', 'show')->name('show');
        Route::patch('{id}', 'update')->name('update');
        Route::delete('{id}', 'destroy')->name('destroy');
        Route::post('{id}/default', 'makeDefault')->name('default');
    });
    Route::controller(V1\Commerce\CheckoutController::class)->group(function () {
        Route::get('cart', 'cart')->name('cart.show');
        Route::get('cart/suggestions', 'suggestions')->name('cart.suggestions');
        Route::get('cart/promise', [V1\Commerce\PromiseController::class, 'cart'])->middleware('throttle:delivery-promise')->name('cart.promise.show');
        Route::put('cart', 'updateCart')->name('cart.update');
        Route::post('cart/items', 'addItem')->name('cart.items.store');
        Route::patch('cart/items/{id}', 'updateItem')->name('cart.items.update');
        Route::delete('cart/items/{id}', 'removeItem')->name('cart.items.destroy');
        Route::post('cart/merge', 'merge')->name('cart.merge');
        Route::post('checkout/quote', 'quote')->name('checkout.quote');
        Route::post('checkout/orders', 'place')->middleware('throttle:checkout')->name('checkout.place');
    });
    // A customer's own return or damage claim: read it, add photos or a note while it waits, cancel it, and see its photos (private files).
    // The customer answers a replacement question: accept it, or have the item removed and refunded.
    Route::post('orders/{id}/substitutions/{requestId}', [V1\Commerce\SubstitutionController::class, 'answer'])->whereUuid(['id', 'requestId'])->middleware('throttle:30,1')->name('orders.substitutions.answer');
    Route::controller(V1\Commerce\ReturnClaimController::class)->prefix('returns/{id}')->whereUuid('id')->group(function () {
        Route::get('/', 'show')->name('returns.show');
        Route::post('evidence', 'evidence')->middleware('throttle:20,1')->name('returns.evidence');
        Route::post('cancel', 'cancel')->middleware('throttle:20,1')->name('returns.cancel');
        Route::get('photos/{photoId}', 'photo')->whereUuid('photoId')->name('returns.photo');
    });
    Route::controller(V1\Commerce\OrderController::class)->group(function () {
        Route::get('orders', 'index')->name('orders.index');
        Route::get('orders/active-progress', 'activeProgress')->name('orders.active-progress');
        Route::get('orders/{id}', 'show')->name('orders.show');
        Route::get('orders/{id}/timeline', 'timeline')->name('orders.timeline');
        Route::get('orders/{id}/tracking', 'tracking')->name('orders.tracking');
        Route::post('orders/{id}/cancel', 'cancel')->name('orders.cancel');
        Route::post('orders/{id}/reorder', 'reorder')->name('orders.reorder');
        Route::get('orders/{id}/invoice', 'invoice')->name('orders.invoice');
        Route::post('orders/{id}/payments', 'pay')->middleware('throttle:checkout')->name('orders.pay');
        Route::post('orders/{id}/returns', 'requestReturn')->middleware('throttle:20,1')->name('orders.returns');
        Route::get('orders/{id}/fulfillments/{fulfillmentId}/pickup-pass', 'pickupPass')->name('orders.pickup-pass');
        Route::get('payments/{id}', 'payment')->name('payments.show');
    });
});

// ── Deals: countdown offers (public; a signed-in customer also sees how many units they may still buy) ──────────
Route::controller(V1\Deals\DealController::class)->group(function () {
    Route::get('deals', 'index')->name('deals.index');
    Route::get('deals/{id}', 'show')->whereUuid('id')->name('deals.show');
});

// ── Auctions ──────────────────────────────────────────────────────────────────
Route::controller(V1\Auctions\AuctionController::class)->group(function () {
    Route::get('auctions', 'index')->name('auctions.index');
    Route::get('auctions/{id}', 'show')->whereUuid('id')->name('auctions.show');
    Route::get('auctions/lots/{lotId}', 'lot')->name('auctions.lots.show');
    Route::get('auctions/lots/{lotId}/bids', 'bids')->name('auctions.lots.bids');
    Route::get('auctions/lots/{lotId}/events', 'events')->name('auctions.lots.events');
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::post('auctions/{id}/register', 'register')->middleware('throttle:20,60')->name('auctions.register');
        Route::post('auctions/lots/{lotId}/bids', 'bid')->middleware('throttle:bids')->name('auctions.lots.bid');
        Route::post('auctions/lots/{lotId}/auto-bid', 'autoBid')->middleware('throttle:bids')->name('auctions.lots.auto-bid');
        Route::delete('auctions/lots/{lotId}/auto-bid', 'cancelAutoBid')->name('auctions.lots.auto-bid.cancel');
        Route::post('auctions/lots/{lotId}/watch', 'watch')->name('auctions.lots.watch');
        Route::delete('auctions/lots/{lotId}/watch', 'unwatch')->name('auctions.lots.unwatch');
        Route::get('me/auctions/bids', 'myBids')->name('me.auctions.bids');
        Route::get('me/auctions/watchlist', 'watchlist')->name('me.auctions.watchlist');
        Route::get('me/auctions/wins', 'wins')->name('me.auctions.wins');
        Route::post('me/auctions/wins/{winnerId}/checkout', 'checkoutWin')->name('me.auctions.wins.checkout');
    });
});

// ── Engagement: home, CMS, notifications, loyalty, wishlist, reviews, support ──
Route::controller(V1\Engagement\EngagementController::class)->group(function () {
    Route::get('home', 'home')->name('home');
    Route::get('homepage', 'home')->name('homepage'); // alias of /home
    Route::get('pages/{slug}', 'page')->name('pages.show');
    Route::get('referrals/status', 'referralStatus')->name('referrals.status');
    Route::get('products/{productId}/reviews', 'reviews')->whereUuid('productId')->name('products.reviews');
    Route::get('products/{productId}/questions', 'questions')->whereUuid('productId')->name('products.questions');
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::get('me/notifications', 'notifications')->name('me.notifications');
        Route::post('me/notifications/read-all', 'readAll')->name('me.notifications.read-all');
        Route::post('me/notifications/{id}/read', 'readNotification')->name('me.notifications.read');
        Route::get('me/notification-preferences', 'preferences')->name('me.preferences');
        Route::put('me/notification-preferences', 'updatePreferences')->name('me.preferences.update');
        Route::get('me/loyalty', 'loyalty')->name('me.loyalty');
        Route::get('me/referral', 'referral')->name('me.referral');
        Route::get('me/buy-again', 'buyAgain')->name('me.buy-again');
        Route::get('me/wishlist', 'wishlist')->name('me.wishlist');
        Route::post('me/wishlist', 'addWishlist')->name('me.wishlist.add');
        Route::delete('me/wishlist/{productId}', 'removeWishlist')->name('me.wishlist.remove');
        Route::post('products/{productId}/reviews', 'submitReview')->whereUuid('productId')->middleware('throttle:10,60')->name('products.reviews.store');
        Route::post('reviews/{id}/report', 'reportReview')->middleware('throttle:30,60')->name('reviews.report');
        Route::post('products/{productId}/questions', 'ask')->whereUuid('productId')->middleware('throttle:10,60')->name('products.questions.store');
        Route::get('me/support/tickets', 'tickets')->name('me.tickets');
        Route::post('me/support/tickets', 'openTicket')->middleware('throttle:10,60')->name('me.tickets.store');
        Route::get('me/support/tickets/{id}', 'ticket')->name('me.tickets.show');
        Route::post('me/support/tickets/{id}/messages', 'replyTicket')->middleware('throttle:30,60')->name('me.tickets.reply');
    });
});

// ── Alerts: "tell me when it is back" and "tell me if the price drops" (config is public; the rest is the signed-in customer's own) ──
Route::get('stock-alerts/config', [V1\Engagement\StockAlertController::class, 'config'])->middleware('throttle:60,1')->name('stock-alerts.config');
Route::middleware(['auth:sanctum', 'customer'])->controller(V1\Engagement\StockAlertController::class)->group(function () {
    Route::get('me/stock-alerts', 'index')->name('me.alerts.index');
    Route::post('me/stock-alerts', 'store')->middleware('throttle:30,1')->name('me.alerts.store');
    Route::delete('me/stock-alerts', 'destroy')->middleware('throttle:30,1')->name('me.alerts.destroy');
    Route::delete('me/stock-alerts/{id}', 'remove')->whereUuid('id')->middleware('throttle:30,1')->name('me.alerts.remove');
});

// ── Store apps: is an update available or required? (public, tiny, never cached) ─────────────────────────
Route::get('app-update', [V1\AppUpdateController::class, 'show'])->middleware('throttle:60,1')->name('app-update');
