<?php

// Staff API routes (prefix /api/v1/admin, middleware auth:sanctum + staff).

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

Route::controller(V1\Admin\BranchAdminController::class)->prefix('branches')->name('branches.')->group(function () {
    Route::get('/', 'index')->middleware('permission:branches.view')->name('index');
    Route::post('/', 'store')->middleware('permission:branches.create')->name('store');
    Route::get('{branch}', 'show')->name('show');
    Route::match(['put', 'patch'], '{branch}', 'update')->name('update');
    Route::put('{branch}/hours', 'updateHours')->name('hours');
    Route::post('{branch}/holidays', 'storeHoliday')->name('holidays.store');
    Route::delete('{branch}/holidays/{holiday}', 'destroyHoliday')->name('holidays.destroy');
    Route::get('{branch}/employees', 'employees')->name('employees');
    Route::post('{branch}/employees', 'assignEmployee')->name('employees.assign');
    Route::delete('{branch}/employees/{user}', 'removeEmployee')->name('employees.remove');
    Route::get('{branch}/zones', 'zones')->name('zones');
    Route::post('{branch}/zones', 'upsertZone')->name('zones.upsert');
});

Route::controller(V1\Admin\CatalogAdminController::class)->prefix('catalog')->name('catalog.')->group(function () {
    Route::get('products', 'index')->middleware('permission:catalog.view')->name('products.index');
    Route::post('products', 'store')->middleware('permission:catalog.create')->name('products.store');
    Route::post('products/bulk', 'bulk')->middleware('permission:catalog.edit')->name('products.bulk');
    Route::post('products/import', 'import')->middleware(['permission:catalog.create', 'throttle:exports'])->name('products.import');
    Route::get('products/{product}', 'show')->middleware('permission:catalog.view')->name('products.show');
    Route::match(['put', 'patch'], 'products/{product}', 'update')->middleware('permission:catalog.edit')->name('products.update');
    Route::post('products/{product}/archive', 'archive')->middleware('permission:catalog.delete')->name('products.archive');
    Route::post('products/{product}/duplicate', 'duplicate')->middleware('permission:catalog.create')->name('products.duplicate');
    Route::post('products/{product}/variants', 'storeVariant')->middleware('permission:catalog.edit')->name('variants.store');
    Route::patch('variants/{variant}', 'updateVariant')->middleware('permission:catalog.edit')->name('variants.update');
    Route::post('products/{product}/branch-settings', 'branchSettings')->middleware('permission:pricing.edit')->name('products.branch-settings');
    Route::get('products/{product}/price-history', 'priceHistory')->middleware('permission:pricing.view')->name('products.price-history');
    Route::post('products/{product}/images', 'uploadImage')->middleware('permission:catalog.edit')->name('images.store');
    Route::post('products/{product}/images/reorder', 'reorderImages')->middleware('permission:catalog.edit')->name('images.reorder');
    Route::delete('images/{image}', 'deleteImage')->middleware('permission:catalog.edit')->name('images.destroy');
    Route::post('categories', 'storeCategory')->middleware('permission:catalog.create')->name('categories.store');
    Route::patch('categories/{category}', 'updateCategory')->middleware('permission:catalog.edit')->name('categories.update');
    Route::delete('categories/{category}', 'destroyCategory')->middleware('permission:catalog.delete')->name('categories.destroy');
    Route::post('brands', 'storeBrand')->middleware('permission:catalog.create')->name('brands.store');
});
