<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash shifts (docs/guides/CASH_SHIFT.md): a cashier opens the branch drawer with a counted float, the system adds up the cash that comes in (hand-ins)
 * and goes out (bank deposits, cash refunds), and the cashier closes it with an end-of-day count that is compared with that expectation.
 *
 *  - One OPEN shift per branch at a time: a partial unique index, so two people (or one double tap) can never open the same drawer twice.
 *  - The Z-report number is per branch, handed out at closing from `cash_shift_counters` (one atomic upsert), so it is sequential and never reused.
 *  - Hand-ins, bank deposits and cash refunds carry the shift they belong to. They are facts that are never moved to another shift afterwards.
 *  - `summary` freezes the figures of the Z-report when the shift is closed. Aggregates and staff names only: nothing here names a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 8)->default('open');                            // open | closed
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->jsonb('opening_notes');                                          // {"500": 2, "200": 0, ...}: how many notes of each value were counted
            $table->decimal('opening_coins', 12, 2)->default(0);
            $table->decimal('opening_total', 12, 2);                                 // the float: notes + coins, worked out by the server
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('closing_notes')->nullable();
            $table->decimal('closing_coins', 12, 2)->nullable();
            $table->decimal('closing_total', 12, 2)->nullable();                     // what the cashier counted at the end
            $table->decimal('expected_total', 12, 2)->nullable();                    // what the system expected, frozen at closing
            $table->decimal('variance', 12, 2)->nullable();                         // counted - expected (negative = short)
            $table->string('variance_note', 500)->nullable();
            $table->string('variance_status', 16)->nullable();                       // open | resolved (only when a second person has to look at it)
            $table->foreignUuid('variance_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('variance_resolved_at')->nullable();
            $table->string('variance_resolution', 500)->nullable();
            $table->unsignedInteger('z_number')->nullable();                         // per branch: Z-1, Z-2 ...
            $table->jsonb('summary')->nullable();                                    // the Z-report figures as they were when the shift was closed
            $table->unsignedSmallInteger('print_count')->default(0);                 // the first print is the original, every later one is marked COPY
            $table->timestampsTz();

            $table->index(['branch_id', 'opened_at']);                              // history of one branch, newest first
            $table->index(['status', 'opened_at']);                                 // "open for too long" and "needs review"
            $table->unique(['branch_id', 'z_number']);                              // a Z number is never used twice (several NULLs are fine for open shifts)
        });
        DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_status_check CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_opening_check CHECK (opening_total >= 0 AND opening_coins >= 0)');
        DB::statement('ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_closed_check CHECK ((status = \'open\' AND closed_at IS NULL AND z_number IS NULL)'
            .' OR (status = \'closed\' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND z_number IS NOT NULL AND closing_total IS NOT NULL AND expected_total IS NOT NULL AND variance IS NOT NULL AND summary IS NOT NULL))');
        DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_variance_status_check CHECK (variance_status IS NULL OR variance_status IN ('open','resolved'))");
        // The drawer of a branch is open once at a time.
        DB::statement("CREATE UNIQUE INDEX cash_shifts_one_open_per_branch ON cash_shifts (branch_id) WHERE status = 'open'");

        Schema::create('cash_shift_counters', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->foreignUuid('cash_shift_id')->nullable()->after('received_by')->constrained('cash_shifts')->restrictOnDelete();   // the shift the cash was received in
            $table->foreignUuid('deposit_shift_id')->nullable()->after('deposited_by')->constrained('cash_shifts')->restrictOnDelete(); // the shift the cash left the drawer in
            $table->index('cash_shift_id');
            $table->index('deposit_shift_id');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignUuid('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();                         // a cash refund paid out of this shift's drawer
            $table->index('cash_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_shift_id');
        });
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_shift_id');
            $table->dropConstrainedForeignId('cash_shift_id');
        });
        Schema::dropIfExists('cash_shift_counters');
        Schema::dropIfExists('cash_shifts');
    }
};
