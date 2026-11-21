<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the branch and driver scorecard (docs/guides/BRANCH_AND_DRIVER_SCORECARD.md): each answers a question the page asks over a period and would
 * otherwise read a whole table. Built CONCURRENTLY (writes keep flowing on a live system), which is why this migration does not run inside a transaction:
 * every statement is safe to run again. Partial indexes keep them small: they only hold the rows that have the date.
 *
 *  - deliveries (branch_id, delivered_at): the deliveries a branch or a driver finished in a period (the on-time rate and the trip time);
 *  - deliveries (order_fulfillment_id): the way from a branch part to its delivery(ies), a foreign key that had no index of its own (the unique
 *    index on it only holds deliveries that are still alive);
 *  - delivery_status_history (delivery_id, to_status): "was this delivery ever moved to another slot?", a foreign key with no index until now;
 *  - order_substitution_requests (branch_id, asked_at): the replacements a branch offered in a period;
 *  - cash_collections (branch_id, collected_at) for cash still not handed in: the cash the drivers hold now, which is a small slice of a table that only grows.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run in a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string, 2?: string}> index name => [table, column(s), optional WHERE] */
    private const INDEXES = [
        'deliveries_branch_delivered_at_index' => ['deliveries', 'branch_id, delivered_at', 'delivered_at IS NOT NULL'],
        'deliveries_order_fulfillment_id_index' => ['deliveries', 'order_fulfillment_id'],
        'delivery_status_history_delivery_status_index' => ['delivery_status_history', 'delivery_id, to_status'],
        'order_substitution_requests_branch_asked_at_index' => ['order_substitution_requests', 'branch_id, asked_at'],
        'cash_collections_held_index' => ['cash_collections', 'branch_id, collected_at', "handed_in_at IS NULL AND tender = 'cash'"],
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
