<?php

// Admin dashboard module routes (prefix /admin, name admin., middleware auth + staff).

use App\Http\Controllers\Admin\DocumentController;
use App\Livewire\Admin;
use Illuminate\Support\Facades\Route;

// ── Organization ──────────────────────────────────────────────────────────────
Route::get('branches', Admin\Branches\BranchIndex::class)->middleware('permission:branches.view')->name('branches.index');
Route::get('branches/create', Admin\Branches\BranchEditor::class)->middleware('permission:branches.create')->name('branches.create');
Route::get('branches/{branch}', Admin\Branches\BranchEditor::class)->middleware('permission:branches.view')->name('branches.edit');
Route::get('zones', Admin\Organization\ZoneIndex::class)->middleware('permission:zones.view')->name('zones.index');
Route::get('employees', Admin\Organization\EmployeeIndex::class)->middleware('permission:employees.view')->name('employees.index');
Route::get('employees/create', Admin\Organization\EmployeeEditor::class)->middleware('permission:employees.create')->name('employees.create');
Route::get('employees/{employee}', Admin\Organization\EmployeeEditor::class)->middleware('permission:employees.view')->name('employees.edit');
Route::get('roles', Admin\Organization\RoleIndex::class)->middleware('permission:roles.view')->name('roles.index');
Route::get('roles/create', Admin\Organization\RoleEditor::class)->middleware('permission:roles.create')->name('roles.create');
Route::get('roles/{role}', Admin\Organization\RoleEditor::class)->middleware('permission:roles.view')->name('roles.edit');
Route::get('settings', Admin\Organization\SettingsIndex::class)->middleware('permission:settings.view')->name('settings.index');

// ── Insights ──────────────────────────────────────────────────────────────────
Route::get('audit', Admin\Organization\AuditIndex::class)->middleware('permission:audit.view')->name('audit.index');

// ── Catalog ───────────────────────────────────────────────────────────────────
Route::get('products', Admin\Catalog\ProductIndex::class)->middleware('permission:catalog.view')->name('products.index');
Route::get('products/create', Admin\Catalog\ProductEditor::class)->middleware('permission:catalog.create')->name('products.create');
Route::get('products/{product}', Admin\Catalog\ProductEditor::class)->middleware('permission:catalog.view')->name('products.edit');
Route::get('categories', Admin\Catalog\CategoryIndex::class)->middleware('permission:catalog.view')->name('categories.index');
Route::get('brands', Admin\Catalog\BrandIndex::class)->middleware('permission:catalog.view')->name('brands.index');
Route::get('attributes', Admin\Catalog\AttributeIndex::class)->middleware('permission:catalog.view')->name('attributes.index');
Route::get('imports', Admin\Catalog\ImportIndex::class)->middleware('permission:catalog.create')->name('imports.index');

// ── Inventory ─────────────────────────────────────────────────────────────────
Route::get('inventory', Admin\Inventory\StockIndex::class)->middleware('permission:inventory.view')->name('inventory.index');
Route::get('inventory/movements', Admin\Inventory\MovementIndex::class)->middleware('permission:inventory.view')->name('inventory.movements');
Route::get('adjustments', Admin\Inventory\AdjustmentIndex::class)->middleware('permission:inventory.create,inventory.approve')->name('adjustments.index');
Route::get('transfers', Admin\Inventory\TransferIndex::class)->middleware('permission:transfers.view')->name('transfers.index');
Route::get('transfers/{transfer}', Admin\Inventory\TransferShow::class)->middleware('permission:transfers.view')->name('transfers.show');
Route::get('stocktaking', Admin\Inventory\StocktakingIndex::class)->middleware('permission:stocktaking.view')->name('stocktaking.index');
Route::get('stocktaking/{session}', Admin\Inventory\StocktakingShow::class)->middleware('permission:stocktaking.view')->name('stocktaking.show');
Route::get('purchase-orders', Admin\Inventory\PurchaseOrderIndex::class)->middleware('permission:purchasing.view')->name('purchase-orders.index');
Route::get('purchase-orders/{po}', Admin\Inventory\PurchaseOrderShow::class)->middleware('permission:purchasing.view')->name('purchase-orders.show');
Route::get('suppliers', Admin\Inventory\SupplierIndex::class)->middleware('permission:suppliers.view')->name('suppliers.index');

// ── Sales ─────────────────────────────────────────────────────────────────────
Route::get('orders', Admin\Sales\OrderIndex::class)->middleware('permission:orders.view')->name('orders.index');
Route::get('orders/{order}', Admin\Sales\OrderShow::class)->middleware('permission:orders.view')->name('orders.show');
Route::get('orders/{order}/invoice', [DocumentController::class, 'invoice'])->middleware('permission:orders.view')->name('orders.invoice');
Route::get('pickup', Admin\Sales\PickupIndex::class)->middleware('permission:pickup.view')->name('pickup.index');
Route::get('pickup/scan', Admin\Sales\PickupScanner::class)->middleware('permission:pickup.view')->name('pickup.scan');
Route::get('deliveries', Admin\Sales\DeliveryIndex::class)->middleware('permission:deliveries.view')->name('deliveries.index');
Route::get('driver', Admin\Sales\DriverConsole::class)->middleware('permission:deliveries.edit')->name('driver');
Route::get('customers', Admin\Sales\CustomerIndex::class)->middleware('permission:customers.view')->name('customers.index');
Route::get('customers/{customer}', Admin\Sales\CustomerShow::class)->middleware('permission:customers.view')->name('customers.show');
Route::get('returns', Admin\Sales\ReturnIndex::class)->middleware('permission:orders.view')->name('returns.index');

// ── Finance ───────────────────────────────────────────────────────────────────
Route::get('payments', Admin\Finance\PaymentIndex::class)->middleware('permission:payments.view')->name('payments.index');
Route::get('payments/{payment}', Admin\Finance\PaymentShow::class)->middleware('permission:payments.view')->name('payments.show');
Route::get('cash', Admin\Finance\CashIndex::class)->middleware('permission:payments.view')->name('cash.index');
Route::get('refunds', Admin\Finance\RefundIndex::class)->middleware('permission:refunds.view')->name('refunds.index');
Route::get('reconciliation', Admin\Finance\ReconciliationIndex::class)->middleware('permission:reconciliation.view')->name('reconciliation.index');
