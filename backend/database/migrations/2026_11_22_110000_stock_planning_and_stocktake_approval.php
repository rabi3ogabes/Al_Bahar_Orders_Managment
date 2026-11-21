<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock planning (roadmap 4.2) and the second person who approves a stock count (roadmap 4.6).
 *
 *  - `branch_inventory.out_since`: since when the item has had nothing available at the branch ("out for 12 days"). InventoryLedger sets it the moment the
 *    available units reach zero and clears it when they are back, so the stock-out report is a plain read, never a scan of the movement history. Rows that are
 *    out today are filled once from the history: the first movement after the last time something was available.
 *  - `branch_inventory.lead_time_days / safety_days / reorder_point / reorder_quantity`: the optional per-item (per branch) rules that replace the shop-wide
 *    defaults of Settings > Stock planning. NULL means "work it out".
 *  - `stocktaking_sessions`: who finished the count (`submitted_by`), the size of the differences at that moment (lines, units, value: the value is NULL when some
 *    item has no cost, so a count with an unknown value is never waved through), how the posting was approved (`approval_mode`), and the send-back
 *    (`returned_by/at/reason`) with its reason, shown on the count until it is submitted again.
 *  - Indexes, one per real query: the stock-out list by branch and age, the counts waiting for approval, and the wish-list count per product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_inventory', function (Blueprint $table) {
            $table->timestampTz('out_since')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedSmallInteger('safety_days')->nullable();
            $table->unsignedInteger('reorder_point')->nullable();
            $table->unsignedInteger('reorder_quantity')->nullable();
        });
        DB::statement('ALTER TABLE branch_inventory ADD CONSTRAINT branch_inventory_planning_check CHECK ((lead_time_days IS NULL OR lead_time_days <= 365) AND (safety_days IS NULL OR safety_days <= 365) AND (reorder_point IS NULL OR reorder_point <= 1000000) AND (reorder_quantity IS NULL OR reorder_quantity <= 1000000))');
        DB::statement('CREATE INDEX branch_inventory_out_since_idx ON branch_inventory (branch_id, out_since) WHERE out_since IS NOT NULL');

        // Rows that are out today: since the first movement that left nothing available after the last time something was (or the last movement, when the history says nothing).
        DB::statement("UPDATE branch_inventory bi SET out_since = coalesce(
            (SELECT min(m.created_at) FROM stock_movements m
              WHERE m.branch_id = bi.branch_id AND m.product_variant_id = bi.product_variant_id AND m.on_hand_after - m.reserved_after <= 0
                AND m.created_at > coalesce((SELECT max(p.created_at) FROM stock_movements p
                      WHERE p.branch_id = bi.branch_id AND p.product_variant_id = bi.product_variant_id AND p.on_hand_after - p.reserved_after > 0), '-infinity'::timestamptz)),
            bi.last_movement_at)
            WHERE bi.on_hand - bi.reserved <= 0 AND bi.last_movement_at IS NOT NULL");

        Schema::table('stocktaking_sessions', function (Blueprint $table) {
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('returned_at')->nullable();
            $table->string('returned_reason', 500)->nullable();
            $table->unsignedInteger('variance_lines')->nullable();
            $table->unsignedInteger('variance_units')->nullable();
            $table->decimal('variance_value', 14, 2)->nullable();
            $table->string('approval_mode', 16)->nullable(); // second_person | self | auto

            $table->index(['status', 'branch_id']);
            $table->index('submitted_by');
            $table->index('returned_by');
        });
        DB::statement("ALTER TABLE stocktaking_sessions ADD CONSTRAINT stocktaking_sessions_approval_mode_check CHECK (approval_mode IS NULL OR approval_mode IN ('second_person','self','auto'))");

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->index(['product_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE stocktaking_sessions DROP CONSTRAINT IF EXISTS stocktaking_sessions_approval_mode_check');
        Schema::table('stocktaking_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'branch_id']);
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['returned_by']);
            $table->dropIndex(['submitted_by']);
            $table->dropIndex(['returned_by']);
            $table->dropColumn(['submitted_by', 'returned_by', 'returned_at', 'returned_reason', 'variance_lines', 'variance_units', 'variance_value', 'approval_mode']);
        });

        DB::statement('DROP INDEX IF EXISTS branch_inventory_out_since_idx');
        DB::statement('ALTER TABLE branch_inventory DROP CONSTRAINT IF EXISTS branch_inventory_planning_check');
        Schema::table('branch_inventory', function (Blueprint $table) {
            $table->dropColumn(['out_since', 'lead_time_days', 'safety_days', 'reorder_point', 'reorder_quantity']);
        });
    }
};
