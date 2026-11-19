<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's numbers, in one migration:
 *
 *  1. Indexes the dashboard, the reports and the order pages were missing. Postgres does not index a foreign-key column by itself, so every
 *     `order_id` join read the whole child table. They are built CONCURRENTLY (writes keep flowing while a live system builds them), which
 *     is why this migration does not run inside a transaction: every statement is therefore written to be safe to run again.
 *  2. `scheduler_heartbeats`: one row per scheduled task, written by the scheduler itself, read by the dashboard's System health card.
 *  3. `marketing_campaign_recipients`: one row per customer per campaign. Its unique key is what makes a campaign send resumable and
 *     idempotent: a retried or doubled delivery job can never message the same customer twice.
 *  4. Progress counters (and who started the send) on `marketing_campaigns` for the "sending" state the page shows.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run in a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string, 2?: string}> index name => [table, column(s), optional WHERE] */
    private const INDEXES = [
        'orders_placed_at_index' => ['orders', 'placed_at'],
        'order_fulfillments_order_id_index' => ['order_fulfillments', 'order_id'],
        'order_items_order_id_index' => ['order_items', 'order_id'],
        'payments_order_id_index' => ['payments', 'order_id'],
        'refunds_order_id_index' => ['refunds', 'order_id'],
        'support_tickets_branch_id_index' => ['support_tickets', 'branch_id'],
        // "Collected today" on the dashboard reads the money that came in during a day.
        'payments_paid_at_index' => ['payments', 'paid_at', 'paid_at IS NOT NULL'],
        'cash_collections_collected_at_index' => ['cash_collections', 'collected_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $def) {
            $this->dropIfBroken($name);
            DB::statement(sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s', $name, $def[0], $def[1], isset($def[2]) ? ' WHERE '.$def[2] : ''));
        }

        if (! Schema::hasTable('scheduler_heartbeats')) {
            Schema::create('scheduler_heartbeats', function (Blueprint $table) {
                $table->string('task', 96)->primary();
                $table->timestampTz('last_started_at')->nullable();
                $table->timestampTz('last_finished_at')->nullable();
                $table->string('last_status', 16)->default('ok');      // ok | failed | running
                $table->unsignedInteger('last_duration_ms')->nullable();
                $table->unsignedSmallInteger('every_minutes')->nullable();  // how often the schedule runs it (read from its cron expression)
                $table->string('last_error', 500)->nullable();
                $table->unsignedBigInteger('run_count')->default(0);
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('marketing_campaign_recipients')) {
            Schema::create('marketing_campaign_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('state', 12);                            // sent | skipped | failed
                $table->jsonb('channels')->nullable();                  // the channels the customer was actually reached on
                $table->string('error', 255)->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['campaign_id', 'user_id']);
            });
        }

        if (! Schema::hasColumn('marketing_campaigns', 'audience_total')) {
            Schema::table('marketing_campaigns', function (Blueprint $table) {
                $table->unsignedInteger('audience_total')->nullable();          // how many customers the send was started for
                $table->unsignedInteger('processed_count')->default(0);         // customers dealt with so far (reached, skipped or failed)
                $table->unsignedInteger('failed_count')->default(0);            // customers whose message errored
                $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete(); // who pressed Send now (null: the scheduler)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_campaigns', 'audience_total')) {
            Schema::table('marketing_campaigns', function (Blueprint $table) {
                $table->dropConstrainedForeignId('started_by');
                $table->dropColumn(['audience_total', 'processed_count', 'failed_count']);
            });
        }
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('scheduler_heartbeats');

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
