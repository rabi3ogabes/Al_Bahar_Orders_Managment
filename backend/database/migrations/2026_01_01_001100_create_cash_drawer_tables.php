<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash drawer — accountability for cash collected on delivery / at pickup:
 *   collector (driver or cashier) --hand-in--> branch cashier --deposit--> bank.
 * A hand-in records what the system expected against what the receiver counted; any difference is kept as a variance
 * that finance reviews. Collections stay immutable facts; they only gain a link to the hand-in that settled them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('collector_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('collections_count');
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('variance', 12, 2);              // counted − expected (negative = short)
            $table->string('status', 16);                    // balanced | short | over
            $table->string('note', 500)->nullable();
            $table->string('variance_status', 16)->nullable(); // open | resolved (only when variance <> 0)
            $table->foreignUuid('variance_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('variance_resolved_at')->nullable();
            $table->string('variance_resolution', 500)->nullable();
            $table->timestampTz('deposited_at')->nullable();
            $table->string('deposit_reference', 128)->nullable();
            $table->foreignUuid('deposited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'created_at']);
            $table->index(['collector_id', 'created_at']);
            $table->index(['branch_id', 'deposited_at']);
        });
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_amounts_check CHECK (expected_amount >= 0 AND counted_amount >= 0 AND collections_count > 0 AND variance = counted_amount - expected_amount)');
        DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_status_check CHECK (status IN ('balanced','short','over'))");
        // Dual control: nobody receives their own cash.
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_dual_control_check CHECK (collector_id <> received_by)');
        DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_variance_status_check CHECK ((variance = 0 AND variance_status IS NULL) OR (variance <> 0 AND variance_status IN ('open','resolved')))");
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_deposit_check CHECK ((deposited_at IS NULL) = (deposit_reference IS NULL))');

        Schema::table('cash_collections', function (Blueprint $table) {
            $table->foreignUuid('cash_handover_id')->nullable()->after('handed_in_to')->constrained('cash_handovers')->restrictOnDelete();
            $table->index('cash_handover_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_handover_id');
        });
        Schema::dropIfExists('cash_handovers');
    }
};
