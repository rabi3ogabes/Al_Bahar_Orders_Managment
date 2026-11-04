<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08 — Store pickup, internal delivery and third-party delivery providers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_collections', function (Blueprint $table) {
            $table->foreign('order_fulfillment_id')->references('id')->on('order_fulfillments')->restrictOnDelete();
        });

        Schema::create('pickup_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_fulfillment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('delivery_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->date('slot_date')->nullable();
            $table->string('status', 16)->default('pending'); // pending | ready | partially_collected | collected | expired | cancelled
            $table->string('token_hash', 64)->nullable()->unique(); // sha256 of opaque QR token
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('token_used_at')->nullable();
            $table->string('alternate_collector_name')->nullable();
            $table->string('alternate_collector_phone', 20)->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->foreignUuid('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('pickup_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pickup_reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('verified_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('result', 24); // success | invalid_token | expired | already_used | wrong_branch | not_ready
            $table->string('collector_name')->nullable();
            $table->boolean('is_alternate_collector')->default(false);
            $table->jsonb('items_handed_over')->nullable(); // [{order_item_id, quantity}]
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('driver', 32); // adapter key registered in DeliveryProviderRegistry
            $table->boolean('is_active')->default(false);
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->jsonb('settings')->nullable();
            $table->jsonb('capabilities')->nullable(); // quote, create_booking, cancel_booking, tracking, webhook, proof
            $table->timestampsTz();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_fulfillment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('mode', 16); // internal | provider
            $table->foreignUuid('delivery_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('tracking_number', 64)->unique();
            $table->string('provider_reference', 128)->nullable();
            $table->decimal('quoted_fee', 12, 2)->nullable();
            $table->decimal('actual_fee', 12, 2)->nullable();
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->boolean('requires_proof')->default(true);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('out_for_delivery_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->jsonb('booking_request')->nullable();  // redacted
            $table->jsonb('booking_response')->nullable(); // redacted
            $table->string('last_error', 500)->nullable();
            $table->timestampsTz();

            $table->index(['tracking_number', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX deliveries_one_active_per_fulfillment ON deliveries (order_fulfillment_id) WHERE status NOT IN ('cancelled','failed','returned')");
        DB::statement("CREATE UNIQUE INDEX deliveries_provider_reference_unique ON deliveries (delivery_provider_id, provider_reference) WHERE provider_reference IS NOT NULL");
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check CHECK (status IN ('pending','assigned','accepted','picked_up','out_for_delivery','delivered','failed','rescheduled','returning','returned','cancelled'))");

        Schema::create('delivery_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->uuid('actor_id')->nullable();
            $table->string('source', 16)->default('staff'); // staff | driver | provider | system
            $table->string('note', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('result', 16); // delivered | failed
            $table->string('failure_reason_code', 48)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('attempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('attempted_at');
            $table->timestampsTz();

            $table->unique(['delivery_id', 'attempt_number']);
        });

        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // photo | signature | provider
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('recipient_name')->nullable();
            $table->foreignUuid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('captured_at');
            $table->timestampsTz();
        });

        Schema::create('delivery_provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_provider_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id', 191);
            $table->string('payload_hash', 64);
            $table->jsonb('payload');
            $table->boolean('signature_valid');
            $table->string('status', 16)->default('received');
            $table->string('error', 500)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['delivery_provider_id', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        foreach (['delivery_provider_events', 'delivery_proofs', 'delivery_attempts', 'delivery_status_history', 'deliveries',
            'delivery_providers', 'pickup_verifications', 'pickup_reservations'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('cash_collections', fn (Blueprint $t) => $t->dropForeign(['order_fulfillment_id']));
    }
};
