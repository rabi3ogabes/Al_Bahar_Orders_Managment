<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes found missing by the plan test at 500,000 orders (docs/guides/SCALE_PROOF.md, `php artisan scale:explain`): each one answers a request-path query
 * that read a whole big table. Built CONCURRENTLY (writes keep flowing on a live system), which is why this migration does not run inside a transaction:
 * every statement is safe to run again. Partial indexes keep them small.
 *
 *  - delivery_proofs (delivery_id, captured_at): the proofs of a delivery (order detail, driver job), a foreign key that had no index;
 *  - cash_collections (payment_id), (order_fulfillment_id, context): the cash of an order (order detail), two foreign keys that had no index;
 *  - user_addresses (user_id, created_at): "my addresses" (the only index was the one-default rule);
 *  - order_fulfillments (branch_id, order_id): "orders that have a part at my branch" (the dashboard's orders list and the staff order list);
 *  - order_fulfillments (status, order_id) for the parts still open: the tab counts, the live board and the needs-attention inbox read only these few rows;
 *  - payments (created_at), and the few payments flagged late (the dashboard's "late capture" count), stock_movements (created_at) and (branch_id, created_at), deliveries (created_at), users (created_at) for customers and (type, created_at): the newest-first lists and "new sign-ups in a period";
 *  - orders trigram index on the customer name: the order search matches name, number and phone from one bitmap;
 *  - order_items covering (order_id) INCLUDE (the report columns): the month-range sales reports read the lines of the orders in range without visiting the table.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run in a transaction block. */
    public $withinTransaction = false;

    /** The statuses of a branch part that is still open (everything but the finished ones). */
    private const OPEN = "'pending','accepted','preparing','ready','dispatched','out_for_delivery','pickup_ready','failed'";

    /** @var array<string, array{0: string, 1: string, 2?: ?string, 3?: string, 4?: string}> index name => [table, column(s), optional WHERE, optional method, optional INCLUDE columns] */
    private const INDEXES = [
        'delivery_proofs_delivery_captured_index' => ['delivery_proofs', 'delivery_id, captured_at'],
        'cash_collections_payment_id_index' => ['cash_collections', 'payment_id'],
        'cash_collections_fulfillment_context_index' => ['cash_collections', 'order_fulfillment_id, context'],
        'user_addresses_user_created_index' => ['user_addresses', 'user_id, created_at', 'deleted_at IS NULL'],
        'order_fulfillments_branch_order_index' => ['order_fulfillments', 'branch_id, order_id'],
        'order_fulfillments_open_index' => ['order_fulfillments', 'status, order_id', 'status IN ('.self::OPEN.')'],
        'payments_created_at_index' => ['payments', 'created_at'],
        'payments_late_capture_index' => ['payments', 'created_at', "metadata->'late_capture' IS NOT NULL"],
        'stock_movements_created_at_index' => ['stock_movements', 'created_at'],
        'stock_movements_branch_created_index' => ['stock_movements', 'branch_id, created_at'],
        'deliveries_created_at_index' => ['deliveries', 'created_at'],
        'users_customers_created_index' => ['users', 'created_at', "type = 'customer' AND deleted_at IS NULL"],
        'users_type_created_index' => ['users', 'type, created_at'],
        'orders_customer_name_trgm_idx' => ['orders', 'customer_name gin_trgm_ops', null, 'gin'],
        'order_items_order_report_index' => ['order_items', 'order_id', null, 'btree', 'order_fulfillment_id, branch_id, product_id, sku, name_en, quantity, quantity_cancelled, quantity_returned, line_subtotal, discount_amount, line_total, unit_final_price, assembly_fee, assembly_total'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $def) {
            $this->dropIfBroken($name);
            $using = isset($def[3]) ? ' USING '.$def[3] : '';
            $include = isset($def[4]) ? " INCLUDE ({$def[4]})" : '';
            DB::statement(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s%s (%s)%s%s', $name, $def[0], $using, $def[1], $include, ! empty($def[2]) ? ' WHERE '.$def[2] : ''));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    /** A CONCURRENTLY build that was interrupted leaves an INVALID index behind, which IF NOT EXISTS would then keep: drop it so it is rebuilt. */
    private function dropIfBroken(string $index): void
    {
        $invalid = DB::selectOne('select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ? and not i.indisvalid', [$index]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }
};
