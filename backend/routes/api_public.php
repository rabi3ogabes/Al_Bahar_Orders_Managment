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
    Route::get('delivery-zones', 'zones')->name('delivery-zones.index');
});

Route::controller(V1\Catalog\CatalogController::class)->group(function () {
    Route::get('categories', 'categories')->name('categories.index');
    Route::get('categories/{id}', 'category')->name('categories.show');
    Route::get('brands', 'brands')->name('brands.index');
    Route::get('products', 'products')->name('products.index');
    Route::get('products/search', 'search')->name('products.search');
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
        Route::post('orders/{id}/cancel', 'cancel')->name('orders.cancel');
        Route::post('orders/{id}/reorder', 'reorder')->name('orders.reorder');
        Route::get('orders/{id}/invoice', 'invoice')->name('orders.invoice');
        Route::post('orders/{id}/payments', 'pay')->middleware('throttle:checkout')->name('orders.pay');
        Route::post('orders/{id}/returns', 'requestReturn')->name('orders.returns');
        Route::get('orders/{id}/fulfillments/{fulfillmentId}/pickup-pass', 'pickupPass')->name('orders.pickup-pass');
        Route::get('payments/{id}', 'payment')->name('payments.show');
    });
});
