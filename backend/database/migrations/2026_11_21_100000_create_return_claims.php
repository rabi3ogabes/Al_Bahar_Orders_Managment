<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return and damage claims with photos (docs/guides/RETURNS_AND_SUBSTITUTIONS.md).
 *
 *  - return_requests gains what a claim needs: the customer's submit key (a double tap never makes two claims), the way the goods come back
 *    (pickup / branch / none), the staff reply, when a review started, the stock decision and when the customer cancelled.
 *  - return_request_photos: the evidence, one row per photo. The file sits on the PRIVATE disk and is reached only through an authorised route.
 *  - return_request_events: the claim's own append-only log (submitted, evidence added, review started, decided, received, ...). The customer's
 *    timeline and the staff window read from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->string('return_method', 12)->nullable();                // pickup | branch | none (the customer keeps it / nothing comes back)
            $table->string('method_note', 300)->nullable();                 // pickup window or instructions, shown to the customer
            $table->text('decision_note')->nullable();                      // the staff reply (customer-visible)
            $table->timestampTz('review_started_at')->nullable();
            $table->foreignUuid('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('stock_decision', 12)->nullable();               // restock | write_off, set when the goods are received
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);                        // the Needs-attention feed and the staff queue
            $table->index('order_id');
        });

        Schema::create('return_request_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime', 24);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64);                                      // of the stored (re-encoded) file: the same photo sent twice is kept once
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['return_request_id', 'sha256']);
            $table->index(['return_request_id', 'created_at']);
        });

        Schema::create('return_request_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->string('kind', 24);                                      // submitted | evidence_added | review_started | approved | rejected | received | refund_requested | cancelled | closed
            $table->string('actor_type', 12);                                // customer | staff | system
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['return_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_events');
        Schema::dropIfExists('return_request_photos');
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('review_started_by');
            $table->dropColumn(['idempotency_key', 'return_method', 'method_note', 'decision_note', 'review_started_at', 'cancelled_at', 'stock_decision']);
        });
    }
};
