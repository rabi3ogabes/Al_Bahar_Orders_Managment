<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Substitution consent (docs/guides/RETURNS_AND_SUBSTITUTIONS.md): when the branch replaces a line with another product (or one that costs more), the
 * customer is asked. order_substitution_requests holds the question and its answer; the replacement line is already in the order (reserved, never
 * dearer than what it replaces) while the question is open, and is taken out again through the normal adjustment when the answer is no or time runs out.
 *
 * order_revisions.reason_code grows two reasons that only the system uses for that removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_substitution_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_revision_id')->nullable()->constrained('order_revisions')->nullOnDelete();
            $table->foreignUuid('original_order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('replacement_order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('waiting');             // waiting | accepted | declined | expired_removed | expired_kept | cancelled
            $table->string('fallback', 8);                                // remove | keep: what happens when time runs out (fixed when the question was asked)
            $table->unsignedSmallInteger('response_minutes');             // the time the customer was given, in opening-hours minutes
            $table->timestampTz('asked_at');
            $table->timestampTz('deadline_at');
            $table->timestampTz('answered_at')->nullable();
            $table->string('answered_source', 10)->nullable();            // customer | system
            $table->decimal('original_total', 12, 2);                     // what the customer pays for the line they ordered
            $table->decimal('replacement_total', 12, 2);                  // what they would pay for the replacement: never more than original_total
            $table->foreignUuid('resolution_revision_id')->nullable()->constrained('order_revisions')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'deadline_at']);
            $table->index('order_id');
            $table->index('order_fulfillment_id');
            $table->index('original_order_item_id');
        });
        DB::statement("ALTER TABLE order_substitution_requests ADD CONSTRAINT order_substitution_requests_status_check CHECK (status IN ('waiting','accepted','declined','expired_removed','expired_kept','cancelled'))");
        DB::statement("ALTER TABLE order_substitution_requests ADD CONSTRAINT order_substitution_requests_fallback_check CHECK (fallback IN ('remove','keep'))");
        DB::statement('ALTER TABLE order_substitution_requests ADD CONSTRAINT order_substitution_requests_price_check CHECK (replacement_total <= original_total)');
        // One open question per replacement line: a second ask for the same line can never be created by a retry or a race.
        DB::statement("CREATE UNIQUE INDEX order_substitution_requests_one_open ON order_substitution_requests (replacement_order_item_id) WHERE status = 'waiting'");

        DB::statement('ALTER TABLE order_revisions DROP CONSTRAINT order_revisions_reason_check');
        DB::statement("ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_reason_check CHECK (reason_code IN ('out_of_stock','not_found','damaged','quality','other','substitute_declined','substitute_expired'))");
    }

    public function down(): void
    {
        // A revision made for a declined or an expired replacement cannot exist under the old rule: it becomes "other" (its note keeps the real reason).
        DB::statement('ALTER TABLE order_revisions DISABLE TRIGGER order_revisions_append_only');
        DB::statement("UPDATE order_revisions SET reason_code = 'other' WHERE reason_code IN ('substitute_declined','substitute_expired')");
        DB::statement('ALTER TABLE order_revisions ENABLE TRIGGER order_revisions_append_only');
        DB::statement('ALTER TABLE order_revisions DROP CONSTRAINT order_revisions_reason_check');
        DB::statement("ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_reason_check CHECK (reason_code IN ('out_of_stock','not_found','damaged','quality','other'))");
        Schema::dropIfExists('order_substitution_requests');
    }
};
