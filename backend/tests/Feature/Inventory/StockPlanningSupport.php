<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\StockPlanning;
use App\Domain\Inventory\Support\PlanRow;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use Illuminate\Support\Str;

// Fixtures for the stock planning tests. require_once keeps them defined once per run.

/** A product variant that costs QAR 40. */
function planVariant(array $product = []): ProductVariant
{
    $variant = Product::factory()->create($product + ['price' => '100.00'])->variants()->first();
    $variant->update(['cost_price' => '40.00']);

    return $variant->fresh();
}

/** Put $qty units of the variant on the shelf of the branch, made $daysAgo days ago (the row's age is what "history" means). */
function planStock(Branch $branch, ProductVariant $variant, int $qty, int $daysAgo = 90): BranchInventory
{
    app(InventoryLedger::class)->apply($branch->id, $variant->id, MovementType::OpeningBalance, $qty, reason: 'opening');
    $stamp = now()->subDays($daysAgo);
    BranchInventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->update(['created_at' => $stamp]);

    return BranchInventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->firstOrFail();
}

/** An order of $qty units of the variant fulfilled by the branch, placed $daysAgo days ago. */
function planSale(Branch $branch, ProductVariant $variant, int $qty, int $daysAgo = 1, array $order = [], array $item = []): Order
{
    $o = Order::create($order + [
        'number' => 'PL'.strtoupper(Str::random(10)), 'user_id' => null, 'source' => 'app', 'status' => 'confirmed', 'payment_status' => 'pending', 'payment_method' => 'cod',
        'fulfillment_type' => 'delivery', 'currency' => 'QAR', 'subtotal' => '100.00', 'delivery_fee_total' => '0.00', 'grand_total' => '100.00', 'customer_name' => 'Noor Ali',
        'placed_at' => now()->subDays($daysAgo),
    ]);
    $f = OrderFulfillment::create(['order_id' => $o->id, 'branch_id' => $branch->id, 'number' => $o->number.'-1', 'type' => 'delivery', 'status' => 'pending', 'subtotal' => '100.00', 'delivery_fee' => '0.00']);
    OrderItem::create($item + ['order_id' => $o->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $branch->id, 'product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
        'sku' => $variant->sku, 'name_en' => 'Sofa', 'name_ar' => 'كنبة', 'unit_price' => '100.00', 'unit_final_price' => '100.00', 'quantity' => $qty, 'line_subtotal' => '100.00', 'line_total' => '100.00']);

    return $o->fresh(['fulfillments', 'items']);
}

/** The planning row of one variant at one branch, for a viewer who sees every branch. */
function planRowOf(Branch $branch, ProductVariant $variant, string $lane = 'all'): ?PlanRow
{
    $scope = BranchScope::all();

    return collect(app(StockPlanning::class)->rows($scope, $lane, '', 100))->first(fn ($r) => $r->branchId === $branch->id && $r->variantId === $variant->id);
}

function planStaff(string $role = 'inventory_manager', ?Branch $branch = null): User
{
    return staffWithRole($role, $branch);
}

/** A purchase order of one line for the branch, in the given state. */
function planPurchase(Branch $branch, ProductVariant $variant, int $ordered, string $status = 'approved', int $received = 0, ?Supplier $supplier = null, string $cost = '40.00'): PurchaseOrder
{
    $supplier ??= Supplier::factory()->create();
    $po = PurchaseOrder::create(['number' => 'PO'.strtoupper(Str::random(8)), 'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'status' => $status,
        'currency' => 'QAR', 'subtotal' => '0.00', 'expected_at' => now()->addDays(5)->toDateString()]);
    $po->items()->create(['product_variant_id' => $variant->id, 'quantity_ordered' => $ordered, 'quantity_received' => $received, 'unit_cost' => $cost, 'line_total' => '0.00']);

    return $po;
}

/** Customers who asked to be told when the variant is back (back-in-stock alerts), for any branch or for one. */
function planWaiting(ProductVariant $variant, int $customers, ?Branch $branch = null, array $attrs = []): void
{
    foreach (range(1, $customers) as $_) {
        StockAlert::create($attrs + ['user_id' => User::factory()->create()->id, 'product_variant_id' => $variant->id, 'product_id' => $variant->product_id,
            'branch_id' => $branch?->id, 'type' => 'back_in_stock', 'expires_at' => now()->addDays(30)]);
    }
}
