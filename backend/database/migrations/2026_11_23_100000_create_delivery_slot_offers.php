<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Failed delivery: new times the customer can choose (docs/guides/DELIVERY_FOLLOW_UPS.md).
 *
 * `delivery_slot_offers` is one offer of up to a few free delivery slots, made to the customer after a delivery failed (by the system, or by the
 * branch from the dashboard). The customer picks one (`chosen_*`), or the offer runs out (`expires_at`), or the branch arranged another time
 * (`cancelled`). `options` is a short fixed list of {slot_id, date} pairs that is only ever read as a whole, never filtered or joined on; what the
 * customer sees of each (capacity left, the time) is read from the slots again when the page is drawn.
 *
 * A delivery has at most ONE open offer (the partial unique index), so a retry or a race can never leave two competing lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_slot_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();       // the customer who may choose
            $table->foreignUuid('offered_by')->nullable()->constrained('users')->nullOnDelete();    // the staff member who proposed it (null: the system)
            $table->string('source', 8);                                                            // system | branch
            $table->string('status', 10)->default('open');                                          // open | chosen | expired | cancelled
            $table->string('failure_reason', 48)->nullable();                                       // why the delivery failed when the offer was made
            $table->jsonb('options');                                                               // [{"slot_id": "...", "date": "2026-11-25"}, ...]
            $table->timestampTz('notify_at')->nullable();                                           // when the customer is to be told (after quiet hours)
            $table->timestampTz('notified_at')->nullable();                                         // when they were told: once
            $table->timestampTz('expires_at');
            $table->timestampTz('chosen_at')->nullable();
            $table->foreignUuid('chosen_slot_id')->nullable()->constrained('delivery_slots')->nullOnDelete();
            $table->date('chosen_date')->nullable();
            $table->string('chosen_key', 128)->nullable();                                          // the Idempotency-Key of the pick: the same key again is a replay
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_reason', 24)->nullable();                                        // why a cancelled offer was cancelled (rescheduled_by_branch, replaced, returned...)
            $table->timestampsTz();

            $table->index(['delivery_id', 'status']);
            $table->index('order_fulfillment_id');
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'notified_at', 'notify_at']);
            $table->index('offered_by');
            $table->index('chosen_slot_id');
        });
        DB::statement("ALTER TABLE delivery_slot_offers ADD CONSTRAINT delivery_slot_offers_status_check CHECK (status IN ('open','chosen','expired','cancelled'))");
        DB::statement("ALTER TABLE delivery_slot_offers ADD CONSTRAINT delivery_slot_offers_source_check CHECK (source IN ('system','branch'))");
        DB::statement("ALTER TABLE delivery_slot_offers ADD CONSTRAINT delivery_slot_offers_options_check CHECK (jsonb_typeof(options) = 'array')");
        DB::statement("CREATE UNIQUE INDEX delivery_slot_offers_one_open ON delivery_slot_offers (delivery_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_slot_offers');
    }
};
