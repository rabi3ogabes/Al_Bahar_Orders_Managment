<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "After delivery" customer journey (roadmap 3.8): a thank-you, a review request and an optional "goes well with it" message after an order is delivered.
 *
 *  - `journey_runs.trigger_order_id`: the delivered order a run was started for. The unique partial index is what makes the journey idempotent per order
 *    (the scheduler can start it a thousand times, the order gets one run). `order_id` keeps its old meaning: the order the journey WON.
 *  - `journey_steps.kind`: which message of the after-delivery journey a step is (`thank_you`, `review`, `suggest`), so the words and the link a step needs
 *    do not depend on a label the owner can rename. Null for the other journeys.
 *  - `journey_deliveries.notification_id`: the inbox entry a message created, so the journey page can show how many were opened (the entry was read).
 *  - Indexes, one per real query: the orders delivered recently (the starting query of every scheduler pass), the sent messages per step (the counts on
 *    the journey page), and the opened-message join.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE journey_runs ADD COLUMN IF NOT EXISTS trigger_order_id uuid NULL REFERENCES orders (id) ON DELETE SET NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS journey_runs_trigger_order_uidx ON journey_runs (journey_id, trigger_order_id) WHERE trigger_order_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS journey_runs_trigger_order_idx ON journey_runs (trigger_order_id) WHERE trigger_order_id IS NOT NULL');
        DB::statement('ALTER TABLE journey_steps ADD COLUMN IF NOT EXISTS kind varchar(16) NULL');
        DB::statement('ALTER TABLE journey_deliveries ADD COLUMN IF NOT EXISTS notification_id uuid NULL REFERENCES notifications (id) ON DELETE SET NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS journey_deliveries_notification_idx ON journey_deliveries (notification_id) WHERE notification_id IS NOT NULL');
        DB::statement("CREATE INDEX IF NOT EXISTS journey_deliveries_step_sent_idx ON journey_deliveries (step_id) WHERE status IN ('sent', 'delivered')");
        DB::statement("CREATE INDEX IF NOT EXISTS orders_completed_at_idx ON orders (completed_at) WHERE status = 'completed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_completed_at_idx');
        DB::statement('DROP INDEX IF EXISTS journey_deliveries_step_sent_idx');
        DB::statement('DROP INDEX IF EXISTS journey_deliveries_notification_idx');
        DB::statement('ALTER TABLE journey_deliveries DROP COLUMN IF EXISTS notification_id');
        DB::statement('ALTER TABLE journey_steps DROP COLUMN IF EXISTS kind');
        DB::statement('DROP INDEX IF EXISTS journey_runs_trigger_order_idx');
        DB::statement('DROP INDEX IF EXISTS journey_runs_trigger_order_uidx');
        DB::statement('ALTER TABLE journey_runs DROP COLUMN IF EXISTS trigger_order_id');
    }
};
