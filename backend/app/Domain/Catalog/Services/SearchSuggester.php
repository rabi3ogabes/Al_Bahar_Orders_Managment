<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Support\SearchNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Search-as-you-type: top products, matching categories and brands (English and Arabic, typo tolerant),
 * plus a "did you mean" correction built word by word from the catalogue's own vocabulary.
 */
class SearchSuggester
{
    public function __construct(private readonly ProductQuery $query, private readonly CatalogPresenter $presenter) {}

    /** @return array{query: string, products: list<array<string, mixed>>, categories: list<array<string, mixed>>, brands: list<array<string, mixed>>, did_you_mean: ?string} */
    public function suggest(string $term, ?Branch $branch, int $limit = 6): array
    {
        $term = trim($term);
        $norm = SearchNormalizer::normalize($term);
        $page = $this->query->build(['q' => $term], $branch?->id)->limit($limit)->get();
        $products = $this->presenter->cards($page, $branch);

        return [
            'query' => $term,
            'products' => $products,
            'categories' => $this->categories($norm),
            'brands' => $this->brands($norm),
            'did_you_mean' => $products === [] ? $this->correction($norm) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(string $norm): array
    {
        if (mb_strlen($norm) < 2) {
            return [];
        }
        $ids = DB::table('category_translations as ct')->join('categories as c', 'c.id', '=', 'ct.category_id')
            ->where('c.status', 'active')
            ->where(fn ($w) => $w->where('ct.name', 'ilike', '%'.addcslashes($norm, '%_\\').'%')->orWhereRaw('word_similarity(?, lower(ct.name)) >= 0.5', [$norm]))
            ->distinct()->limit(4)->pluck('c.id');

        return Category::active()->with('translations')->whereIn('id', $ids)->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->trans('name')])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function brands(string $norm): array
    {
        if (mb_strlen($norm) < 2) {
            return [];
        }
        $like = '%'.addcslashes($norm, '%_\\').'%';

        return Brand::where('status', 'active')
            ->where(fn ($w) => $w->where('name_en', 'ilike', $like)->orWhere('name_ar', 'ilike', $like)->orWhereRaw('word_similarity(?, lower(name_en)) >= 0.5', [$norm]))
            ->orderBy('sort_order')->limit(4)->get()
            ->map(fn (Brand $b) => ['id' => $b->id, 'slug' => $b->slug, 'name' => $b->localized('name')])->values()->all();
    }

    /** Replaces each word that is not in the catalogue with its closest catalogue word; null when nothing changed. */
    private function correction(string $norm): ?string
    {
        if ($norm === '') {
            return null;
        }
        $fixed = [];
        $changed = false;
        foreach (explode(' ', $norm) as $word) {
            $best = mb_strlen($word) >= 3 ? $this->closestWord($word) : null;
            if ($best !== null && $best !== $word) {
                $changed = true;
            }
            $fixed[] = $best ?? $word;
        }

        return $changed ? implode(' ', $fixed) : null;
    }

    private function closestWord(string $word): ?string
    {
        return Cache::remember('search:word:'.md5($word), 600, function () use ($word) {
            $row = DB::selectOne(
                "SELECT w FROM (SELECT DISTINCT unnest(string_to_array(search_text, ' ')) AS w FROM product_translations) v
                 WHERE length(w) >= 3 AND similarity(w, ?) >= 0.3
                 ORDER BY (w = ?) DESC, similarity(w, ?) DESC, length(w) ASC LIMIT 1",
                [$word, $word, $word]
            );

            return $row->w ?? '';
        }) ?: null;
    }
}
