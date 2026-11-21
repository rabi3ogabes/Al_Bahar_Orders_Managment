<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the daily close, the weekly owner pack and the offer returns: every one answers a question those pages ask of a branch and a day (or a
 * coupon and a window) and would otherwise read a whole table. Built CONCURRENTLY (writes keep flowing on a live system), which is why this migration
 * does not run inside a transaction: every statement is safe to run again. Partial indexes keep them small: they only hold the rows that have the date.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run in a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string, 2?: string}> index name => [table, column(s), optional WHERE] */
    private const INDEXES = [
        'order_fulfillments_branch_delivered_at_index' => ['order_fulfillments', 'branch_id, delivered_at', 'delivered_at IS NOT NULL'],
        'order_fulfillments_branch_picked_up_at_index' => ['order_fulfillments', 'branch_id, picked_up_at', 'picked_up_at IS NOT NULL'],
        'order_fulfillments_branch_cancelled_at_index' => ['order_fulfillments', 'branch_id, cancelled_at', 'cancelled_at IS NOT NULL'],
        'deliveries_branch_failed_at_index' => ['deliveries', 'branch_id, failed_at', 'failed_at IS NOT NULL'],
        'refunds_processed_at_index' => ['refunds', 'processed_at', "status = 'processed'"],
        'return_requests_branch_created_at_index' => ['return_requests', 'branch_id, created_at'],
        'return_requests_branch_received_at_index' => ['return_requests', 'branch_id, received_at', 'received_at IS NOT NULL'],
        'cash_handovers_created_at_index' => ['cash_handovers', 'created_at'],
        // The offer returns start from the usage rows of a coupon or a promotion and join to the orders they were used on.
        'coupon_usage_order_id_index' => ['coupon_usage', 'order_id'],
        'coupon_usage_coupon_id_index' => ['coupon_usage', 'coupon_id', 'coupon_id IS NOT NULL'],
        'marketing_campaigns_sent_at_index' => ['marketing_campaigns', 'sent_at', 'sent_at IS NOT NULL'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $def) {
            $this->dropIfBroken($name);
            DB::statement(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s', $name, $def[0], $def[1], isset($def[2]) ? ' WHERE '.$def[2] : ''));
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
