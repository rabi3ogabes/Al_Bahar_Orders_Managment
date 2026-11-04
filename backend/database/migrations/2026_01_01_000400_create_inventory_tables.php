<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 04 — Independent branch inventory, stock ledger and purchasing.
 *
 * Ledger model: branch_inventory.on_hand INCLUDES reserved units.
 *   available = on_hand - reserved   (never negative: CHECK reserved <= on_hand)
 * Every change to on_hand or reserved is written by App\Domain\Inventory\Services\InventoryLedger
 * inside a transaction with a row lock, and produces an immutable stock_movements row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('minimum_stock')->default(0);
            $table->integer('maximum_stock')->nullable();
            $table->decimal('average_cost', 12, 4)->nullable();
            $table->string('bin_location', 64)->nullable();
            $table->timestampTz('last_counted_at')->nullable();
            $table->timestampTz('last_movement_at')->nullable();
            $table->timestampsTz();

            $table->unique(['branch_id', 'product_variant_id']);
            $table->index('product_variant_id');
        });
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_on_hand_check CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_reserved_check CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_available_check CHECK (reserved <= on_hand)');
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_minmax_check CHECK (minimum_stock >= 0 AND (maximum_stock IS NULL OR maximum_stock >= minimum_stock))');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity');          // signed delta applied to on_hand
            $table->integer('reserved_delta')->default(0); // signed delta applied to reserved
            $table->integer('on_hand_after');
            $table->integer('reserved_after');
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('reference_type', 48)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('reverses_movement_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['branch_id', 'product_variant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'created_at']);
        });
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN (
            'opening_balance','purchase_receipt','sale_deduction','reservation','reservation_release',
            'transfer_out','transfer_in','adjustment_in','adjustment_out','customer_return','damaged','lost',
            'correction','reversal','stocktake_correction'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_nonzero_check CHECK (quantity <> 0 OR reserved_delta <> 0)');
        DB::unprepared('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()');

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->string('status', 16)->default('active'); // active | consumed | released | expired
            $table->string('reference_type', 48);              // order_item | transfer_item | auction_lot
            $table->string('reference_id', 64);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            // One reservation per referenced line: prevents duplicate reservation for the same order item.
            $table->unique(['reference_type', 'reference_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['branch_id', 'product_variant_id', 'status']);
        });
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_qty_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('active','consumed','released','expired'))");

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->index(['source_branch_id', 'status']);
            $table->index(['destination_branch_id', 'status']);
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_branches_check CHECK (source_branch_id <> destination_branch_id)');
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('draft','pending_approval','approved','in_transit','partially_received','received','completed','rejected','cancelled'))");

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_requested');
            $table->integer('quantity_approved')->default(0);
            $table->integer('quantity_dispatched')->default(0);
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_damaged')->default(0);
            $table->text('discrepancy_note')->nullable();
            $table->timestampsTz();

            $table->unique(['stock_transfer_id', 'product_variant_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT sti_qty_check CHECK (
            quantity_requested > 0 AND quantity_approved >= 0 AND quantity_approved <= quantity_requested
            AND quantity_dispatched >= 0 AND quantity_dispatched <= quantity_approved
            AND quantity_received >= 0 AND quantity_damaged >= 0
            AND quantity_received + quantity_damaged <= quantity_dispatched)');

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 24); // adjustment | damaged | lost | opening_balance | customer_return
            $table->string('status', 16)->default('draft'); // draft | pending_approval | posted | rejected
            $table->string('reason', 500);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('inventory_adjustment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity'); // signed delta
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE inventory_adjustment_items ADD CONSTRAINT iai_qty_check CHECK (quantity <> 0)');

        Schema::create('stocktaking_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('counting'); // counting | submitted | posted | cancelled
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete(); // optional scope
            $table->text('notes')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX stocktaking_one_open_per_branch ON stocktaking_sessions (branch_id) WHERE status IN ('counting','submitted')");

        Schema::create('stocktaking_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stocktaking_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('expected_quantity');
            $table->integer('counted_quantity')->nullable();
            $table->foreignUuid('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('counted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['stocktaking_session_id', 'product_variant_id']);
        });
        DB::statement('ALTER TABLE stocktaking_items ADD CONSTRAINT sti2_qty_check CHECK (expected_quantity >= 0 AND (counted_quantity IS NULL OR counted_quantity >= 0))');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('payment_terms')->nullable();
            $table->char('currency', 3)->default('QAR');
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete(); // receiving branch
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('QAR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('supplier_reference')->nullable();
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','pending_approval','approved','partially_received','received','closed','cancelled','rejected'))");

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('line_total', 14, 2);
            $table->timestampsTz();

            $table->unique(['purchase_order_id', 'product_variant_id']);
        });
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT poi_qty_check CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND unit_cost >= 0)');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->timestampsTz();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_received');
            $table->integer('quantity_rejected')->default(0);
            $table->decimal('unit_cost', 12, 4);
            $table->string('rejection_reason')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT gri_qty_check CHECK (quantity_received >= 0 AND quantity_rejected >= 0 AND quantity_received + quantity_rejected > 0)');
    }

    public function down(): void
    {
        foreach (['goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'suppliers',
            'stocktaking_items', 'stocktaking_sessions', 'inventory_adjustment_items', 'inventory_adjustments',
            'stock_transfer_items', 'stock_transfers', 'stock_reservations', 'stock_movements', 'branch_inventory'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
