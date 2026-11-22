<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Assembly done" in the driver app (docs/guides/DELIVERY_FOLLOW_UPS.md): the driver's answer for the paid assembly of one branch part.
 *
 *  - assembly_status         null until the driver answers; `done` or `not_done`
 *  - assembly_answered_at/by when and who answered (either answer)
 *  - assembly_done_at        the moment the assembly was done: set ONLY for `done` (the one column a report reads: "assembled on")
 *  - assembly_reason / note  why it was not done (a short code from a fixed list) and an optional word from the driver
 *  - assembly_handled_at/by  the branch dealt with a `not_done` (refunded the fee, rebooked it): it leaves the Needs attention inbox
 *
 * The optional photo of the assembly is a `delivery_proofs` row of type `assembly` (the same private storage as every other proof photo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->string('assembly_status', 10)->nullable();
            $table->timestampTz('assembly_answered_at')->nullable();
            $table->foreignUuid('assembly_answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assembly_done_at')->nullable();
            $table->string('assembly_reason', 24)->nullable();
            $table->string('assembly_note', 300)->nullable();
            $table->timestampTz('assembly_handled_at')->nullable();
            $table->foreignUuid('assembly_handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('assembly_answered_by');
            $table->index('assembly_handled_by');
        });
        DB::statement("ALTER TABLE order_fulfillments ADD CONSTRAINT order_fulfillments_assembly_status_check CHECK (assembly_status IS NULL OR assembly_status IN ('done','not_done'))");
        // The Needs attention inbox reads only the answers nobody has dealt with yet: a tiny index of exactly those rows.
        DB::statement("CREATE INDEX order_fulfillments_assembly_open ON order_fulfillments (branch_id, assembly_answered_at) WHERE assembly_status = 'not_done' AND assembly_handled_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS order_fulfillments_assembly_open');
        DB::statement('ALTER TABLE order_fulfillments DROP CONSTRAINT IF EXISTS order_fulfillments_assembly_status_check');
        Schema::table('order_fulfillments', function (Blueprint $table) {
            $table->dropIndex(['assembly_answered_by']);
            $table->dropIndex(['assembly_handled_by']);
            $table->dropConstrainedForeignId('assembly_answered_by');
            $table->dropConstrainedForeignId('assembly_handled_by');
            $table->dropColumn(['assembly_status', 'assembly_answered_at', 'assembly_done_at', 'assembly_reason', 'assembly_note', 'assembly_handled_at']);
        });
    }
};
