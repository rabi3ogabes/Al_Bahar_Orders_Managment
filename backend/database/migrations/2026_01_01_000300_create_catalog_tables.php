<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 03 — Central catalog, categories, variants and branch pricing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('slug', 160)->unique();
            $table->string('image_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active'); // active | inactive
            $table->jsonb('filter_attribute_ids')->nullable(); // category-specific filters
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('path', 2000)->default(''); // materialised path of ancestor ids "/a/b/"
            $table->timestampsTz();
        });
        Schema::table('categories', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete());
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('category_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['category_id', 'locale']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique(); // color, size, material, finish, package_size
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('type', 16)->default('select'); // select | color | text
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_variant_axis')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('value_en');
            $table->string('value_ar');
            $table->string('color_hex', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['product_attribute_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft | active | inactive | archived
            $table->boolean('has_variants')->default(false);
            $table->char('currency', 3)->default('QAR');
            $table->decimal('price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->boolean('allow_sale_above_price')->default(false);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit', 4)->default('kg');
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->string('dimension_unit', 4)->default('cm');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_bestseller')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->boolean('requires_assembly')->default(false);
            $table->boolean('assembly_service_available')->default(false);
            $table->boolean('is_oversized')->default(false);
            $table->boolean('available_in_all_branches')->default(true);
            $table->jsonb('tags')->nullable();
            $table->jsonb('specifications')->nullable(); // [{label_en,label_ar,value_en,value_ar}]
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedBigInteger('sales_count')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'category_id']);
            $table->index(['status', 'brand_id']);
            $table->index(['is_featured', 'status']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','active','inactive','archived'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price >= 0 AND (sale_price IS NULL OR sale_price >= 0))');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sale_check CHECK (sale_price IS NULL OR allow_sale_above_price OR sale_price <= price)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sale_window_check CHECK (sale_starts_at IS NULL OR sale_ends_at IS NULL OR sale_ends_at > sale_starts_at)');

        Schema::create('product_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->string('slug', 200);
            $table->string('short_description', 1000)->nullable();
            $table->text('description')->nullable();
            $table->string('material')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('search_keywords', 1000)->nullable();
            $table->text('search_text')->default(''); // normalised (Arabic-folded, lower-cased) searchable text
            $table->timestampsTz();

            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
        DB::statement('CREATE INDEX product_translations_search_trgm ON product_translations USING gin (search_text gin_trgm_ops)');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name_en')->nullable(); // e.g. "Walnut / 180cm"; generated from attributes if empty
            $table->string('name_ar')->nullable();
            $table->decimal('price', 12, 2)->nullable(); // NULL = inherit product price
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->string('status', 16)->default('active'); // active | inactive
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'status']);
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check CHECK ((price IS NULL OR price >= 0) AND (sale_price IS NULL OR sale_price >= 0))');
        DB::statement('CREATE UNIQUE INDEX product_variants_one_default ON product_variants (product_id) WHERE is_default');

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_value_id')->constrained()->restrictOnDelete();
            $table->primary(['product_variant_id', 'product_attribute_value_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->jsonb('variants')->nullable(); // {"320": "path", "640": "path"}
            $table->string('alt_en')->nullable();
            $table->string('alt_ar')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16); // upload | youtube | vimeo
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('url')->nullable();
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('product_branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['product_id', 'branch_id']);
            $table->index('branch_id');
        });
        DB::statement('ALTER TABLE product_branch_settings ADD CONSTRAINT pbs_price_check CHECK ((price IS NULL OR price >= 0) AND (sale_price IS NULL OR sale_price >= 0))');

        Schema::create('product_variant_branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['product_variant_id', 'branch_id']);
            $table->index('branch_id');
        });
        DB::statement('ALTER TABLE product_variant_branch_settings ADD CONSTRAINT pvbs_price_check CHECK ((price IS NULL OR price >= 0) AND (sale_price IS NULL OR sale_price >= 0))');

        Schema::create('product_warranties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_months');
            $table->string('provider')->nullable(); // manufacturer | store
            $table->text('terms_en')->nullable();
            $table->text('terms_ar')->nullable();
            $table->timestampsTz();
        });

        Schema::create('product_related', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 16)->default('related'); // related | upsell | cross_sell
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['product_id', 'related_product_id', 'type']);
        });
        DB::statement('ALTER TABLE product_related ADD CONSTRAINT product_related_not_self CHECK (product_id <> related_product_id)');

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->decimal('old_sale_price', 12, 2)->nullable();
            $table->decimal('new_sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });
        DB::unprepared('CREATE TRIGGER product_price_history_append_only BEFORE UPDATE OR DELETE ON product_price_history FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION prevent_ledger_mutation()');

        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->default('products');
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('status', 16)->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->jsonb('errors')->nullable(); // [{row, field, message}]
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        // Customer stock/price alerts (Phase 10 "back in stock" / "price drop").
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16); // back_in_stock | price_drop
            $table->decimal('reference_price', 12, 2)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'product_variant_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['stock_alerts', 'catalog_imports', 'product_price_history', 'product_related', 'product_warranties',
            'product_variant_branch_settings', 'product_branch_settings', 'product_videos', 'product_images',
            'product_variant_values', 'product_variants', 'product_translations', 'products', 'product_attribute_values',
            'product_attributes', 'brands', 'category_translations', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
