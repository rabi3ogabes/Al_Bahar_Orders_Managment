<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion exclusions (docs/guides/PROMOTION_EXCLUSIONS.md): "the whole basket EXCEPT these". A promotion can leave out specific products, whole categories
 * (with their sub-categories) and brands. The engine never discounts a line that is excluded, whatever else the promotion targets, and a coupon of the
 * promotion inherits the exclusions because the discount is always worked out from the promotion.
 * The second column of each pair is indexed too: deleting a product, category or brand must not scan the whole table.
 */
return new class extends Migration
{
    private const PAIRS = ['promotion_excluded_products' => 'product_id', 'promotion_excluded_categories' => 'category_id', 'promotion_excluded_brands' => 'brand_id'];

    public function up(): void
    {
        foreach (self::PAIRS as $table => $column) {
            Schema::create($table, function (Blueprint $t) use ($column) {
                $t->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
                $t->foreignUuid($column)->constrained()->cascadeOnDelete();
                $t->primary(['promotion_id', $column]);
                $t->index($column);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PAIRS) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
