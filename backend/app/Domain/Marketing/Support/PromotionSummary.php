<?php

namespace App\Domain\Marketing\Support;

use App\Domain\Marketing\Models\Promotion;
use Illuminate\Support\Str;

/**
 * What a promotion covers and what it leaves out, in a plain line for the Promotions list (docs/guides/PROMOTION_EXCLUSIONS.md):
 * "Whole basket except 3 products, 1 brand", "2 categories except 1 product". The numbers are the counts the list loads with the promotion
 * (`products_count`, `categories_count`, `excluded_*_count`); a promotion loaded without them is counted here.
 */
final class PromotionSummary
{
    public static function of(Promotion $p, ?string $locale = null): string
    {
        $count = fn (string $name): int => (int) ($p->getAttribute(Str::snake($name).'_count') ?? $p->{$name}()->count());
        $say = fn (string $key, int $n): string => trans_choice('promotion_exclusions.summary.'.$key, $n, ['count' => $n], $locale);

        $covers = match ($p->scope) {
            'products' => $say('products', $count('products')),
            'categories' => $say('categories', $count('categories')),
            default => __('promotion_exclusions.summary.order', [], $locale),
        };
        $left = array_values(array_filter([
            ($n = $count('excludedProducts')) > 0 ? $say('n_products', $n) : null,
            ($n = $count('excludedCategories')) > 0 ? $say('n_categories', $n) : null,
            ($n = $count('excludedBrands')) > 0 ? $say('n_brands', $n) : null,
        ]));

        return $left === [] ? $covers : __('promotion_exclusions.summary.except', ['what' => $covers, 'list' => implode(__('promotion_exclusions.summary.glue', [], $locale), $left)], $locale);
    }
}
