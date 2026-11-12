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

Route::get('theme', [V1\Cms\ThemeController::class, 'show'])->name('theme');

Route::controller(V1\Catalog\CatalogController::class)->group(function () {
    Route::get('categories', 'categories')->name('categories.index');
    Route::get('categories/{id}', 'category')->name('categories.show');
    Route::get('brands', 'brands')->name('brands.index');
    Route::get('products', 'products')->name('products.index');
    Route::get('products/search', 'search')->name('products.search');
    Route::get('search/suggest', 'suggest')->middleware('throttle:120,1')->name('search.suggest');
    Route::get('products/{id}/availability', 'availability')->whereUuid('id')->name('products.availability');
    Route::get('products/{idOrSlug}', 'product')->name('products.show');
    Route::get('branches/{branchId}/products', 'branchProducts')->whereUuid('branchId')->name('branches.products');
});

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
        Route::put('cart', 'updateCart')->name('cart.update');
        Route::post('cart/items', 'addItem')->name('cart.items.store');
        Route::patch('cart/items/{id}', 'updateItem')->name('cart.items.update');
        Route::delete('cart/items/{id}', 'removeItem')->name('cart.items.destroy');
        Route::post('cart/merge', 'merge')->name('cart.merge');
        Route::post('checkout/quote', 'quote')->name('checkout.quote');
        Route::post('checkout/orders', 'place')->middleware('throttle:checkout')->name('checkout.place');
    });
    Route::controller(V1\Commerce\OrderController::class)->group(function () {
        Route::get('orders', 'index')->name('orders.index');
        Route::get('orders/{id}', 'show')->name('orders.show');
        Route::get('orders/{id}/timeline', 'timeline')->name('orders.timeline');
        Route::get('orders/{id}/tracking', 'tracking')->name('orders.tracking');
        Route::post('orders/{id}/cancel', 'cancel')->name('orders.cancel');
        Route::post('orders/{id}/reorder', 'reorder')->name('orders.reorder');
        Route::get('orders/{id}/invoice', 'invoice')->name('orders.invoice');
        Route::post('orders/{id}/payments', 'pay')->middleware('throttle:checkout')->name('orders.pay');
        Route::post('orders/{id}/returns', 'requestReturn')->name('orders.returns');
        Route::get('orders/{id}/fulfillments/{fulfillmentId}/pickup-pass', 'pickupPass')->name('orders.pickup-pass');
        Route::get('payments/{id}', 'payment')->name('payments.show');
    });
});

// ── Auctions ──────────────────────────────────────────────────────────────────
Route::controller(V1\Auctions\AuctionController::class)->group(function () {
    Route::get('auctions', 'index')->name('auctions.index');
    Route::get('auctions/{id}', 'show')->whereUuid('id')->name('auctions.show');
    Route::get('auctions/lots/{lotId}', 'lot')->name('auctions.lots.show');
    Route::get('auctions/lots/{lotId}/bids', 'bids')->name('auctions.lots.bids');
    Route::get('auctions/lots/{lotId}/events', 'events')->name('auctions.lots.events');
    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::post('auctions/{id}/register', 'register')->name('auctions.register');
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
    Route::get('pages/{slug}', 'page')->name('pages.show');
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
        Route::post('products/{productId}/reviews', 'submitReview')->whereUuid('productId')->name('products.reviews.store');
        Route::post('reviews/{id}/report', 'reportReview')->name('reviews.report');
        Route::post('products/{productId}/questions', 'ask')->whereUuid('productId')->name('products.questions.store');
        Route::post('me/stock-alerts', 'addAlert')->name('me.alerts.store');
        Route::delete('me/stock-alerts', 'removeAlert')->name('me.alerts.destroy');
        Route::get('me/support/tickets', 'tickets')->name('me.tickets');
        Route::post('me/support/tickets', 'openTicket')->name('me.tickets.store');
        Route::get('me/support/tickets/{id}', 'ticket')->name('me.tickets.show');
        Route::post('me/support/tickets/{id}/messages', 'replyTicket')->name('me.tickets.reply');
    });
});

// ── Store apps: is an update available or required? (public, tiny, never cached) ─────────────────────────
Route::get('app-update', [V1\AppUpdateController::class, 'show'])->middleware('throttle:60,1')->name('app-update');
