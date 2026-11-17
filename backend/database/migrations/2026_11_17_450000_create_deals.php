<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deals: countdown offers with a limited quantity (docs/api/DEALS.md).
 *
 *  - deals: one countdown + a price rule + limits + a widget design. The phase (scheduled / live / sold_out / ended / paused) is computed, never stored;
 *    `ended_at` is set by "End now", `sold_out_at` only remembers when the last unit went (the list keeps a sold-out deal visible for 15 minutes).
 *  - deal_products: the products inside a deal with their own quantity and limits. A CHECK keeps quantity_sold from ever passing quantity_total, so
 *    even a bug cannot oversell.
 *  - deal_redemptions: the units an order took from a deal product (one row per order and deal product). `released_at` is set when the order is
 *    cancelled / expires / is removed by an adjustment; the units then go back to the deal and to the customer's own allowance.
 *  - order_items / order_coupons: the snapshot of the deal on the placed order (invoices and revised invoices never read the live deal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('tagline_en')->nullable();
            $table->string('tagline_ar')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestampTz('starts_at')->nullable();           // null = from the moment it is switched on
            $table->timestampTz('ends_at');
            $table->timestampTz('ended_at')->nullable();            // "End now"
            $table->timestampTz('sold_out_at')->nullable();         // the moment the last unit went (cleared when a unit comes back)
            $table->string('discount_type', 16);                    // percent | amount_off | fixed_price
            $table->decimal('discount_value', 12, 2)->default(0);   // percent 1-95, amount_off > 0, unused for fixed_price
            $table->unsignedSmallInteger('limit_per_order')->nullable();
            $table->unsignedSmallInteger('limit_per_customer')->nullable();
            $table->boolean('show_on_home')->default(false);
            $table->integer('sort_order')->default(0);
            $table->jsonb('design')->nullable();                    // DealDesign allow-list; null = the defaults
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'ends_at']);
            $table->index('show_on_home');
        });
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_discount_type_check CHECK (discount_type IN ('percent','amount_off','fixed_price'))");
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_window_check CHECK (starts_at IS NULL OR ends_at > starts_at)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_value_check CHECK (discount_value >= 0)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_limits_check CHECK ((limit_per_order IS NULL OR limit_per_order >= 1) AND (limit_per_customer IS NULL OR limit_per_customer >= 1))');

        Schema::create('deal_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('deal_price', 12, 2)->nullable();       // required for fixed_price; otherwise a per-product override
            $table->unsignedInteger('quantity_total')->nullable();  // null = unlimited
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedSmallInteger('limit_per_order')->nullable();
            $table->unsignedSmallInteger('limit_per_customer')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['deal_id', 'product_id']);
            $table->index('product_id');
        });
        DB::statement('ALTER TABLE deal_products ADD CONSTRAINT deal_products_sold_check CHECK (quantity_sold >= 0 AND (quantity_total IS NULL OR quantity_sold <= quantity_total))');
        DB::statement('ALTER TABLE deal_products ADD CONSTRAINT deal_products_limits_check CHECK ((limit_per_order IS NULL OR limit_per_order >= 1) AND (limit_per_customer IS NULL OR limit_per_customer >= 1) AND (quantity_total IS NULL OR quantity_total >= 1) AND (deal_price IS NULL OR deal_price > 0))');

        Schema::create('deal_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('deal_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('deal_product_id')->constrained('deal_products')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('units');
            $table->decimal('unit_savings', 12, 2);
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['order_id', 'deal_product_id']);        // also "release by order"
            $table->index('deal_id');
        });
        // "Units this customer already bought in this deal product": only the redemptions that were not released.
        DB::statement('CREATE INDEX deal_redemptions_customer_idx ON deal_redemptions (user_id, deal_product_id) WHERE released_at IS NULL');
        DB::statement('ALTER TABLE deal_redemptions ADD CONSTRAINT deal_redemptions_units_check CHECK (units >= 0 AND unit_savings >= 0)');

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignUuid('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->string('deal_name_en')->nullable();
            $table->string('deal_name_ar')->nullable();
            $table->unsignedInteger('deal_units')->default(0);
            $table->decimal('deal_unit_savings', 12, 2)->nullable();
        });
        Schema::table('order_coupons', function (Blueprint $table) {
            $table->foreignUuid('deal_id')->nullable()->constrained('deals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_id');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_id');
            $table->dropColumn(['deal_name_en', 'deal_name_ar', 'deal_units', 'deal_unit_savings']);
        });
        Schema::dropIfExists('deal_redemptions');
        Schema::dropIfExists('deal_products');
        Schema::dropIfExists('deals');
    }
};
