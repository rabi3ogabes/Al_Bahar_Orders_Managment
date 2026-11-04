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

Route::controller(V1\Admin\InventoryAdminController::class)->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('stock', 'stock')->middleware('permission:inventory.view')->name('stock');
    Route::patch('stock/{inventory}', 'updateThresholds')->middleware('permission:inventory.edit')->name('stock.thresholds');
    Route::get('movements', 'movements')->middleware('permission:inventory.view')->name('movements');
    Route::post('adjustments', 'storeAdjustment')->middleware('permission:inventory.create')->name('adjustments.store');
    Route::post('adjustments/{adjustment}/post', 'postAdjustment')->middleware('permission:inventory.approve')->name('adjustments.post');
    Route::get('transfers', 'transfers')->middleware('permission:transfers.view')->name('transfers.index');
    Route::post('transfers', 'storeTransfer')->middleware('permission:transfers.create')->name('transfers.store');
    Route::post('transfers/{transfer}/{action}', 'transferAction')->middleware('permission:transfers.view')->whereIn('action', ['submit', 'approve', 'reject', 'dispatch', 'receive', 'complete', 'cancel'])->name('transfers.action');
    Route::post('stocktakes', 'startStocktake')->middleware('permission:stocktaking.create')->name('stocktakes.store');
    Route::post('stocktakes/{session}/{action}', 'stocktakeAction')->middleware('permission:stocktaking.view')->whereIn('action', ['count', 'submit', 'post', 'cancel'])->name('stocktakes.action');
    Route::get('suppliers', 'suppliers')->middleware('permission:suppliers.view')->name('suppliers.index');
    Route::post('suppliers', 'storeSupplier')->middleware('permission:suppliers.create')->name('suppliers.store');
    Route::post('purchase-orders', 'storePurchaseOrder')->middleware('permission:purchasing.create')->name('purchase-orders.store');
    Route::post('purchase-orders/{po}/{action}', 'purchaseOrderAction')->middleware('permission:purchasing.view')->whereIn('action', ['submit', 'approve', 'reject', 'receive', 'close', 'cancel'])->name('purchase-orders.action');
});
