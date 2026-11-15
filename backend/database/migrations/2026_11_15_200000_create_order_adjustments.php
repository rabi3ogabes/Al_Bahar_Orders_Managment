<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order adjustments: the branch finds an item short while preparing and reduces, replaces or removes it (docs/api/ORDER_ADJUSTMENTS.md).
 *
 *  - order_revisions: one row per staff request (numbered 1, 2, … per order, append-only audit trail), with the totals before/after, the reason,
 *    who did it and the refund that was asked for. order_revision_lines: one row per changed item (queryable: which products run short, and why).
 *  - order_items: a line may now reach quantity 0 (removed or swapped out; it stays on the order so the customer sees it struck through) and
 *    carries markers for the customer payload (original quantity, kind, reason, which line it replaces).
 *  - orders.adjustment_count: how many revisions the order has (the next revision number, and "was adjusted" without a query per order).
 *  - order_invoices: one row per revision (the latest wins; older ones stay for audit). The invoice number stays the same across revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('adjustment_count')->default(0);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('original_quantity')->nullable();                 // the quantity ordered, set the first time the line is adjusted
            $table->string('adjustment_kind', 12)->nullable();                // reduced | removed | replaced | replacement
            $table->string('adjustment_reason', 24)->nullable();              // the reason code of the revision that changed it
            $table->foreignUuid('replaces_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->index('replaces_order_item_id');
        });
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_qty_check');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity >= 0 AND quantity_cancelled >= 0 AND quantity_returned >= 0 AND quantity_handed_over >= 0 AND quantity_cancelled + quantity_handed_over <= quantity AND quantity_returned <= quantity_handed_over)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_adjustment_kind_check CHECK (adjustment_kind IS NULL OR adjustment_kind IN ('reduced','removed','replaced','replacement'))");

        Schema::create('order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 24);                                // out_of_stock | not_found | damaged | quality | other
            $table->string('note', 500)->nullable();
            $table->jsonb('before');                                          // subtotal, discount_total, tax_total, grand_total, loyalty_discount
            $table->jsonb('after');
            $table->decimal('difference', 12, 2);                             // after − before (zero or negative)
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->char('request_hash', 64)->nullable();                     // sha256 of the normalised request: a lost-response retry of it is answered with this revision
            $table->unsignedSmallInteger('loyalty_points_returned')->default(0);
            $table->boolean('fulfillment_cancelled')->default(false);
            $table->boolean('order_cancelled')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['order_id', 'number']);
            $table->index('order_fulfillment_id');
            $table->index('refund_id');
        });
        DB::statement("ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_reason_check CHECK (reason_code IN ('out_of_stock','not_found','damaged','quality','other'))");
        DB::statement('ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_difference_check CHECK (difference <= 0)');
        DB::unprepared('CREATE TRIGGER order_revisions_append_only BEFORE UPDATE OR DELETE ON order_revisions FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION prevent_ledger_mutation()');

        Schema::create('order_revision_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_revision_id')->constrained('order_revisions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('action', 8);                                      // reduce | remove | replace
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('replacement_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('variant_name_en')->nullable();
            $table->string('variant_name_ar')->nullable();
            $table->integer('from_quantity');
            $table->integer('to_quantity');
            $table->decimal('unit_price', 12, 2);                             // what the customer paid per unit of the original line
            $table->decimal('line_total_before', 12, 2);
            $table->decimal('line_total_after', 12, 2);
            $table->unsignedInteger('stock_corrected')->default(0);           // units taken off the branch's on-hand count (out_of_stock / not_found)
            $table->jsonb('replacement')->nullable();                          // the substitute: variant, names in both languages, quantity, unit price

            $table->index('order_revision_id');
            $table->index('order_item_id');
        });
        DB::statement("ALTER TABLE order_revision_lines ADD CONSTRAINT order_revision_lines_action_check CHECK (action IN ('reduce','remove','replace'))");
        DB::statement('ALTER TABLE order_revision_lines ADD CONSTRAINT order_revision_lines_qty_check CHECK (from_quantity > 0 AND to_quantity >= 0 AND to_quantity < from_quantity)');

        // Invoices: one row per revision, the latest wins; the number stays (the customer's reference does not change).
        Schema::table('order_invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(1);
            $table->dropUnique(['order_id']);
            $table->dropUnique(['number']);
            $table->unique(['order_id', 'revision']);
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::table('order_invoices', function (Blueprint $table) {
            $table->dropIndex(['number']);
            $table->dropUnique(['order_id', 'revision']);
        });
        // Only the latest revision of each order survives a rollback (the original unique keys need one invoice per order).
        DB::statement('DELETE FROM order_invoices a USING order_invoices b WHERE a.order_id = b.order_id AND a.revision < b.revision');
        Schema::table('order_invoices', function (Blueprint $table) {
            $table->dropColumn('revision');
            $table->unique(['order_id']);
            $table->unique(['number']);
        });

        Schema::dropIfExists('order_revision_lines');
        Schema::dropIfExists('order_revisions');

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_adjustment_kind_check');
        // Lines at quantity 0 cannot exist under the original rule: they are cancelled out of existence with the rollback.
        DB::statement('DELETE FROM order_items WHERE quantity = 0');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_qty_check');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0 AND quantity_cancelled >= 0 AND quantity_returned >= 0 AND quantity_handed_over >= 0 AND quantity_cancelled + quantity_handed_over <= quantity AND quantity_returned <= quantity_handed_over)');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['replaces_order_item_id']);
            $table->dropConstrainedForeignId('replaces_order_item_id');
            $table->dropColumn(['original_quantity', 'adjustment_kind', 'adjustment_reason']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('adjustment_count');
        });
    }
};
