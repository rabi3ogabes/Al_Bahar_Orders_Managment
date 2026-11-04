<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 09 — Scheduled & live auctions. PostgreSQL is the authoritative bid ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 24)->unique();
            $table->string('type', 16); // scheduled | live
            $table->string('status', 16)->default('draft'); // draft | scheduled | live | paused | closed | cancelled
            $table->string('cover_image_path')->nullable();
            $table->string('video_url')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('requires_registration')->default(true);
            $table->boolean('requires_verified_phone')->default(true);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('winner_payment_hours')->default(48);
            $table->boolean('cod_allowed')->default(false);
            $table->boolean('pickup_allowed')->default(true);
            $table->boolean('delivery_allowed')->default(true);
            $table->unsignedInteger('extension_window_seconds')->default(120);
            $table->unsignedInteger('extension_seconds')->default(120);
            $table->unsignedSmallInteger('max_extensions')->nullable(); // NULL = unlimited
            $table->string('unpaid_winner_policy', 24)->default('offer_next_bidder');
            $table->boolean('show_bidder_names')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'starts_at', 'ends_at']);
        });
        DB::statement("ALTER TABLE auctions ADD CONSTRAINT auctions_type_check CHECK (type IN ('scheduled','live'))");
        DB::statement("ALTER TABLE auctions ADD CONSTRAINT auctions_status_check CHECK (status IN ('draft','scheduled','live','paused','closed','cancelled'))");

        Schema::create('auction_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->timestampsTz();

            $table->unique(['auction_id', 'locale']);
        });

        Schema::create('auction_branches', function (Blueprint $table) {
            $table->foreignUuid('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['auction_id', 'branch_id']);
        });

        Schema::create('auction_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('lot_number');
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('starting_price', 12, 2);
            $table->decimal('reserve_price', 12, 2)->nullable(); // private
            $table->decimal('min_increment', 12, 2);
            $table->decimal('buy_now_price', 12, 2)->nullable();
            $table->decimal('current_price', 12, 2)->nullable();
            $table->uuid('current_bid_id')->nullable();
            $table->foreignUuid('current_leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('bid_count')->default(0);
            $table->unsignedBigInteger('last_bid_number')->default(0);
            $table->unsignedBigInteger('last_event_sequence')->default(0);
            $table->string('status', 16)->default('pending'); // pending | open | paused | closed | sold | unsold | cancelled
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('original_ends_at')->nullable();
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->timestampTz('paused_at')->nullable();
            $table->unsignedInteger('paused_remaining_seconds')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['auction_id', 'lot_number']);
            $table->index(['status', 'ends_at']);
        });
        DB::statement("ALTER TABLE auction_lots ADD CONSTRAINT auction_lots_status_check CHECK (status IN ('pending','open','paused','closed','sold','unsold','cancelled'))");
        DB::statement('ALTER TABLE auction_lots ADD CONSTRAINT auction_lots_money_check CHECK (starting_price >= 0 AND min_increment > 0 AND (reserve_price IS NULL OR reserve_price >= 0) AND (buy_now_price IS NULL OR buy_now_price > starting_price) AND quantity > 0)');

        // Link between lots and catalog stock (the spec's auction_products).
        Schema::create('auction_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete(); // stock source
            $table->unsignedInteger('quantity')->default(1);
            $table->timestampsTz();

            $table->unique(['auction_lot_id', 'product_variant_id']);
        });

        Schema::create('auction_bidders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('approved'); // pending_deposit | approved | blocked
            $table->string('paddle_number', 16);
            $table->timestampTz('terms_accepted_at');
            $table->string('blocked_reason', 500)->nullable();
            $table->foreignUuid('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['auction_id', 'user_id']);
            $table->unique(['auction_id', 'paddle_number']);
        });

        Schema::create('auction_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_lot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bid_number')->nullable(); // sequence within lot; NULL for rejected
            $table->decimal('amount', 12, 2);
            $table->string('type', 16); // manual | auto | buy_now | floor
            $table->string('status', 16); // accepted | rejected | retracted
            $table->string('rejection_reason', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('placed_at', 6);
            $table->timestampsTz();

            $table->unique(['auction_lot_id', 'bid_number']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['auction_lot_id', 'placed_at', 'bid_number']);
        });
        DB::statement('ALTER TABLE auction_bids ADD CONSTRAINT auction_bids_accepted_number_check CHECK ((status = \'accepted\') = (bid_number IS NOT NULL) OR status = \'retracted\')');

        Schema::table('auction_lots', function (Blueprint $table) {
            $table->foreign('current_bid_id')->references('id')->on('auction_bids')->nullOnDelete();
        });

        Schema::create('auction_auto_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('max_amount', 12, 2);
            $table->string('status', 16)->default('active'); // active | exhausted | cancelled | won | lost
            $table->timestampTz('set_at', 6); // tie policy: earliest maximum wins
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX auction_auto_bids_one_active ON auction_auto_bids (auction_lot_id, user_id) WHERE status = 'active'");

        Schema::create('auction_extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('auction_bid_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('previous_ends_at');
            $table->timestampTz('new_ends_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('auction_winners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_lot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('auction_bid_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('rank')->default(1); // 1 = original winner, 2 = next-bidder offer...
            $table->string('status', 24)->default('pending_confirmation'); // pending_confirmation | pending_payment | paid | defaulted | declined | cancelled | fulfilled
            $table->timestampTz('payment_due_at');
            $table->uuid('order_id')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('defaulted_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX auction_winners_one_final ON auction_winners (auction_lot_id) WHERE status NOT IN ('defaulted','declined','cancelled')");
        Schema::table('orders', fn (Blueprint $t) => $t->foreign('auction_winner_id')->references('id')->on('auction_winners')->nullOnDelete());
        Schema::table('auction_winners', fn (Blueprint $t) => $t->foreign('order_id')->references('id')->on('orders')->nullOnDelete());

        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 16)->default('pending'); // pending | paid | applied | refunded | forfeited
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['auction_id', 'user_id']);
        });

        Schema::create('auction_lot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 32); // opened | bid_accepted | extended | paused | resumed | closed | cancelled | override | price_updated
            $table->jsonb('payload');   // public payload (never includes reserve or auto-bid maxima)
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(['auction_lot_id', 'sequence']);
        });

        Schema::create('auction_watchlists', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'auction_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropForeign(['auction_winner_id']));
        foreach (['auction_watchlists', 'auction_lot_events', 'auction_deposits'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('auction_winners', fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('auction_lots', fn (Blueprint $t) => $t->dropForeign(['current_bid_id']));
        foreach (['auction_winners', 'auction_extensions', 'auction_auto_bids', 'auction_bids', 'auction_bidders',
            'auction_products', 'auction_lots', 'auction_branches', 'auction_translations', 'auctions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
