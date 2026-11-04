<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 06 — Addresses, carts, checkout, orders and multi-branch fulfillment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START 100001');

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 48)->nullable(); // Home, Office...
            $table->string('recipient_name');
            $table->string('recipient_phone', 20);
            $table->foreignUuid('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zone_number', 8)->nullable();
            $table->string('street_number', 16)->nullable();
            $table->string('building_number', 16)->nullable();
            $table->string('unit_number', 16)->nullable();
            $table->string('area')->nullable();
            $table->string('city')->default('Doha');
            $table->string('landmark')->nullable();
            $table->text('instructions')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX user_addresses_one_default ON user_addresses (user_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete(); // preferred branch
            $table->string('fulfillment_type', 16)->default('delivery'); // delivery | pickup
            $table->foreignUuid('address_id')->nullable()->constrained('user_addresses')->nullOnDelete();
            $table->string('coupon_code', 48)->nullable();
            $table->unsignedInteger('loyalty_points_to_redeem')->default(0);
            $table->string('status', 16)->default('active'); // active | converted | merged | abandoned
            $table->uuid('converted_order_id')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampTz('abandoned_notified_at')->nullable();
            $table->unsignedSmallInteger('abandoned_notifications')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'last_activity_at']);
        });
        DB::statement("CREATE UNIQUE INDEX carts_one_active_per_user ON carts (user_id) WHERE status = 'active' AND user_id IS NOT NULL");

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->decimal('price_at_add', 12, 2)->nullable(); // informational, for "price changed" warnings only
            $table->timestampsTz();

            $table->unique(['cart_id', 'product_variant_id']);
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_qty_check CHECK (quantity > 0)');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('app'); // app | web | admin | auction
            $table->string('status', 32)->default('pending_payment');
            $table->string('payment_status', 24)->default('pending');
            $table->string('payment_method', 24); // sadad | cod | pay_at_pickup
            $table->string('fulfillment_type', 16); // delivery | pickup
            $table->char('currency', 3)->default('QAR');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_fee_total', 12, 2)->default(0);
            $table->decimal('service_fee_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('loyalty_discount', 12, 2)->default(0);
            $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_email')->nullable();
            $table->jsonb('address_snapshot')->nullable();
            $table->jsonb('fee_breakdown')->nullable();
            $table->jsonb('tax_snapshot')->nullable();
            $table->string('locale', 5)->default('en');
            $table->text('customer_notes')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('quote_hash', 64)->nullable();
            $table->uuid('auction_winner_id')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('payment_due_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment','confirmed','processing','partially_fulfilled','ready_for_pickup','out_for_delivery','completed','cancellation_requested','cancelled','partially_cancelled','returned'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending','processing','authorized','paid','failed','cancelled','expired','partially_refunded','refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IN ('sadad','cod','pay_at_pickup'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal >= 0 AND discount_total >= 0 AND delivery_fee_total >= 0 AND grand_total >= 0 AND paid_total >= 0 AND refunded_total >= 0 AND refunded_total <= paid_total)');

        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 32)->unique(); // e.g. AB100123-1
            $table->string('type', 16); // delivery | pickup
            $table->string('status', 24)->default('pending');
            $table->foreignUuid('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->foreignUuid('delivery_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->date('slot_date')->nullable();
            $table->boolean('is_express')->default(false);
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // preparer
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('allocation_reason', 64)->nullable(); // selected_branch | single_branch | split | admin_override
            $table->timestampsTz();

            $table->index(['branch_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE order_fulfillments ADD CONSTRAINT of_status_check CHECK (status IN ('pending','accepted','preparing','ready','dispatched','out_for_delivery','delivered','pickup_ready','picked_up','failed','cancelled','returned'))");

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('variant_name_en')->nullable();
            $table->string('variant_name_ar')->nullable();
            $table->jsonb('variant_attributes')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('unit_price', 12, 2);       // regular price at order time
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('unit_final_price', 12, 2); // price charged per unit before order-level discounts
            $table->integer('quantity');
            $table->decimal('line_subtotal', 12, 2);    // unit_final_price * quantity
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->integer('quantity_cancelled')->default(0);
            $table->integer('quantity_returned')->default(0);
            $table->integer('quantity_handed_over')->default(0);
            $table->string('price_source', 32)->nullable(); // which precedence level priced the line
            $table->timestampsTz();

            $table->index('branch_id');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0 AND quantity_cancelled >= 0 AND quantity_returned >= 0 AND quantity_handed_over >= 0 AND quantity_cancelled + quantity_handed_over <= quantity AND quantity_returned <= quantity_handed_over)');

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // order | fulfillment | payment
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_customer_visible')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
        DB::unprepared('CREATE TRIGGER order_status_history_append_only BEFORE UPDATE OR DELETE ON order_status_history FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION prevent_ledger_mutation()');

        Schema::create('order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->timestampsTz();
        });

        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_fulfillment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_type', 16); // customer | staff | system
            $table->string('reason_code', 48);
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('requested'); // requested | approved | rejected | completed
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('order_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->jsonb('snapshot'); // immutable invoice data at issue time
            $table->timestampTz('issued_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['order_invoices', 'order_cancellations', 'order_notes', 'order_status_history', 'order_items',
            'order_fulfillments', 'orders', 'cart_items', 'carts', 'user_addresses'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
