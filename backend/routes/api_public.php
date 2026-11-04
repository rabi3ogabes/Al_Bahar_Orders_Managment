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
