<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Contracts\ProductSearch;
use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * PostgreSQL search: Arabic-normalised trigram + substring matching on product translations (both languages),
 * exact/prefix matching on product and variant SKU/barcode. Relevance = exact code > name prefix > similarity.
 */
class PostgresProductSearch implements ProductSearch
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $term, bool $orderByRelevance = true): Builder
    {
        $norm = SearchNormalizer::normalize($term);
        $raw = trim($term);
        if ($norm === '' && $raw === '') {
            return $query;
        }
        $like = '%'.addcslashes($norm, '%_\\').'%';
        $code = strtoupper($raw);

        $query->where(function ($w) use ($like, $norm, $code) {
            $w->whereExists(fn ($s) => $s->from('product_translations as pt')->whereColumn('pt.product_id', 'products.id')
                ->where(fn ($m) => $m->where('pt.search_text', 'like', $like)
                    ->orWhereRaw('word_similarity(?, pt.search_text) >= 0.5', [$norm])))
                ->orWhere('products.sku', 'ilike', addcslashes($code, '%_\\').'%')
                ->orWhere('products.barcode', $code)
                ->orWhereExists(fn ($s) => $s->from('product_variants as pv')->whereColumn('pv.product_id', 'products.id')
                    ->where(fn ($m) => $m->where('pv.sku', 'ilike', addcslashes($code, '%_\\').'%')->orWhere('pv.barcode', $code)));
        });

        if ($orderByRelevance) {
            $query->orderByRaw(
                '(CASE WHEN upper(products.sku) = ? OR products.barcode = ? THEN 3 ELSE 0 END)
                 + COALESCE((SELECT max(CASE WHEN pt.search_text LIKE ? THEN 1.5 ELSE 0 END + word_similarity(?, pt.search_text))
                             FROM product_translations pt WHERE pt.product_id = products.id), 0) DESC',
                [$code, $code, addcslashes($norm, '%_\\').'%', $norm]
            );
        }

        return $query;
    }
}
