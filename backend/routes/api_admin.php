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

Route::controller(V1\Admin\OrderOpsController::class)->group(function () {
    Route::get('orders', 'index')->middleware('permission:orders.view')->name('orders.index');
    Route::get('orders/{order}', 'show')->middleware('permission:orders.view')->name('orders.show');
    Route::post('orders/{order}/cancel', 'cancel')->middleware('permission:orders.approve')->name('orders.cancel');
    Route::post('orders/{order}/notes', 'note')->middleware('permission:orders.view')->name('orders.notes');
    Route::post('fulfillments/{fulfillment}/transition', 'transition')->middleware('permission:orders.edit')->name('fulfillments.transition');
    Route::post('cancellations/{cancellation}/decide', 'decideCancellation')->middleware('permission:orders.approve')->name('cancellations.decide');
    Route::post('fulfillments/{fulfillment}/pickup-ready', 'pickupReady')->middleware('permission:pickup.edit')->name('pickup.ready');
    Route::post('pickup/verify', 'pickupVerify')->middleware('permission:pickup.view')->name('pickup.verify');
    Route::post('pickup/{reservation}/handover', 'pickupHandover')->middleware('permission:pickup.approve')->name('pickup.handover');
    Route::post('fulfillments/{fulfillment}/deliveries', 'createDelivery')->middleware('permission:deliveries.create')->name('deliveries.store');
    Route::post('deliveries/{delivery}/{action}', 'deliveryAction')->middleware('permission:deliveries.edit')->whereIn('action', ['assign', 'accept', 'pickup', 'out-for-delivery', 'deliver', 'fail', 'reschedule', 'return', 'cancel', 'retry-booking'])->name('deliveries.action');
    Route::get('me/deliveries', 'myDeliveries')->middleware('permission:deliveries.view')->name('deliveries.mine');
    Route::post('payments/{payment}/collections', 'collect')->middleware('permission:payments.create')->name('payments.collect');
    Route::post('payments/{payment}/refunds', 'requestRefund')->middleware('permission:refunds.create')->name('refunds.store');
    Route::post('refunds/{refund}/{action}', 'refundAction')->middleware('permission:refunds.view')->whereIn('action', ['approve', 'reject', 'processed'])->name('refunds.action');
});

Route::controller(V1\Admin\AuctionAdminController::class)->prefix('auctions')->name('auctions.')->group(function () {
    Route::post('/', 'store')->middleware('permission:auctions.create')->name('store');
    Route::patch('{auction}', 'update')->middleware('permission:auctions.edit')->name('update');
    Route::post('{auction}/lots', 'addLot')->middleware('permission:auctions.edit')->name('lots.store');
    Route::post('{auction}/publish', 'publish')->middleware('permission:auctions.approve')->name('publish');
    Route::post('{auction}/cancel', 'cancel')->middleware('permission:auctions.delete')->name('cancel');
    Route::patch('lots/{lot}', 'updateLot')->middleware('permission:auctions.edit')->name('lots.update');
    Route::delete('lots/{lot}', 'deleteLot')->middleware('permission:auctions.delete')->name('lots.destroy');
    Route::post('lots/{lot}/{action}', 'lotAction')->middleware('permission:auctions.edit')->whereIn('action', ['open', 'pause', 'resume', 'close', 'cancel', 'extend'])->name('lots.action');
    Route::get('lots/{lot}/feed', 'feed')->middleware('permission:auctions.view')->name('lots.feed');
    Route::post('bids/{bid}/retract', 'retractBid')->middleware('permission:auctions.override')->name('bids.retract');
    Route::post('bidders/{bidder}/block', 'blockBidder')->middleware('permission:auctions.override')->name('bidders.block');
});

Route::controller(V1\Admin\CashDrawerController::class)->prefix('cash')->name('cash.')->group(function () {
    Route::get('outstanding', 'outstanding')->middleware('permission:payments.view')->name('outstanding');
    Route::get('handovers', 'handovers')->middleware('permission:payments.view')->name('handovers');
    Route::post('handovers', 'handIn')->middleware('permission:payments.edit')->name('handovers.store');
    Route::post('handovers/{handover}/resolve', 'resolve')->middleware('permission:payments.override')->name('handovers.resolve');
    Route::post('deposits', 'deposit')->middleware('permission:payments.edit')->name('deposits.store');
});

Route::controller(V1\Admin\ReportAdminController::class)->group(function () {
    Route::get('reports/{report}', 'run')->middleware('permission:reports.view')->name('reports.run');
    Route::post('exports', 'export')->middleware(['permission:reports.export', 'throttle:exports'])->name('exports.store');
    Route::get('exports', 'exports')->middleware('permission:reports.export')->name('exports.index');
    Route::get('exports/{export}/download', 'download')->middleware('permission:reports.export')->name('exports.download');
});

// ── Mobile dispatch (driver app + branch staff app) ──────────────────────────
Route::controller(V1\Admin\DispatchController::class)->prefix('dispatch')->name('dispatch.')->group(function () {
    Route::get('driver', 'driver')->name('driver');
    Route::put('driver/duty', 'duty')->name('driver.duty');
    Route::post('driver/location', 'location')->middleware('throttle:60,1')->name('driver.location');
    Route::get('driver/jobs', 'jobs')->name('driver.jobs');
    Route::get('overview', 'overview')->middleware('permission:orders.view')->name('overview');
    Route::get('queue', 'queue')->middleware('permission:orders.view')->name('queue');
    Route::get('drivers', 'drivers')->middleware('permission:deliveries.view')->name('drivers');
    Route::post('fulfillments/{fulfillment}/assign', 'assign')->middleware('permission:deliveries.assign')->name('assign');
});
