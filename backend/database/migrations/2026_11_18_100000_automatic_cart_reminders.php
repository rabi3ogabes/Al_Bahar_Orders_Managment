<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Automatic abandoned-cart reminders: one engine (the customer journey `abandoned_cart`), one set of counters (`carts.abandoned_notifications`).
 *
 *  - `journey_runs.next_at`: the moment a run needs attention again (a step comes due, quiet hours end). The scheduler reads only runs that are due
 *    (`status = 'active' AND next_at <= now()`), so a pile of runs waiting for their second reminder can never starve the new ones. The index is partial
 *    (active runs only) and tiny. Adding a nullable column and a small partial index is instant on a live table; nothing is rewritten.
 *  - `journey_runs (cart_id)`: "has this cart already got a run for this idle period" (every scheduler pass), the cart window's history, and the foreign key from
 *    carts (deleting a cart looked at every run). EXPLAIN on 200,000 carts and 60,000 runs: the start query went from a parallel sequential scan of the runs to index probes.
 *  - Three small partial indexes on `journey_deliveries`, one per real query: the once-a-minute sweep for deliveries that were left `queued`, and the "reminders
 *    sent" and "could not be sent" counts of the abandoned orders page (by time). Partial, so they hold only the rows those queries want.
 *  - Reminders sent by the journey before this change were only in `journey_deliveries`; from now on every reminder (automatic or sent by staff) also
 *    moves the cart's own counters, which is what the abandoned orders page and the "most reminders per cart" limit read. This copies what was already
 *    sent to the carts that are still open, once (only the idle episode the cart is in now: a customer's return resets the counters).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE journey_runs ADD COLUMN IF NOT EXISTS next_at timestamptz NULL');
        DB::statement("UPDATE journey_runs SET next_at = anchor_at WHERE status = 'active' AND next_at IS NULL");
        DB::statement("CREATE INDEX IF NOT EXISTS journey_runs_due_idx ON journey_runs (journey_id, next_at) WHERE status = 'active'");

        DB::statement('CREATE INDEX IF NOT EXISTS journey_runs_cart_idx ON journey_runs (cart_id)');
        DB::statement("CREATE INDEX IF NOT EXISTS journey_deliveries_queued_idx ON journey_deliveries (created_at) WHERE status = 'queued'");
        DB::statement("CREATE INDEX IF NOT EXISTS journey_deliveries_sent_at_idx ON journey_deliveries (sent_at) WHERE status IN ('sent', 'delivered')");
        DB::statement("CREATE INDEX IF NOT EXISTS journey_deliveries_failed_idx ON journey_deliveries (created_at) WHERE status = 'failed'");

        DB::statement(<<<'SQL'
            UPDATE carts SET
                abandoned_notifications = LEAST(65535, carts.abandoned_notifications + s.sent),
                abandoned_notified_at = GREATEST(carts.abandoned_notified_at, s.last_sent)
            FROM (
                SELECT jr.cart_id, count(DISTINCT coalesce(jd.step_id::text, jd.id::text)) AS sent, max(jd.sent_at) AS last_sent
                FROM journey_runs jr
                JOIN journeys j ON j.id = jr.journey_id AND j.key = 'abandoned_cart'
                JOIN carts c ON c.id = jr.cart_id AND c.status = 'active' AND jr.anchor_at = c.last_activity_at
                JOIN journey_deliveries jd ON jd.run_id = jr.id AND jd.status IN ('sent', 'delivered')
                GROUP BY jr.cart_id
            ) s
            WHERE carts.id = s.cart_id
        SQL);
    }

    public function down(): void
    {
        // The counters keep what they hold (they are plain facts about messages that were sent); only the structure goes.
        DB::statement('DROP INDEX IF EXISTS journey_deliveries_failed_idx');
        DB::statement('DROP INDEX IF EXISTS journey_deliveries_sent_at_idx');
        DB::statement('DROP INDEX IF EXISTS journey_deliveries_queued_idx');
        DB::statement('DROP INDEX IF EXISTS journey_runs_cart_idx');
        DB::statement('DROP INDEX IF EXISTS journey_runs_due_idx');
        DB::statement('ALTER TABLE journey_runs DROP COLUMN IF EXISTS next_at');
    }
};
