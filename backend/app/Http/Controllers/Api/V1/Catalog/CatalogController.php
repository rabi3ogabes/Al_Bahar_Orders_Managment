<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductQuery;
use App\Domain\Catalog\Services\SearchSuggester;
use App\Domain\Pricing\Services\PriceResolver;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @tags Catalog */
class CatalogController extends ApiController
{
    public function __construct(private readonly CatalogPresenter $presenter, private readonly ProductQuery $query) {}

    public function categories(CategoryService $categories): JsonResponse
    {
        $locale = app()->getLocale();
        $tree = Cache::remember("catalog:categories:{$locale}", 300, fn () => $this->categoryTree($categories->tree()));

        return response()->json(['data' => $tree]);
    }

    public function category(string $id): JsonResponse
    {
        $category = Category::active()->with(['translations', 'children' => fn ($q) => $q->active()->with('translations')])
            ->where(fn ($q) => $q->whereKey(Str::isUuid($id) ? $id : null)->orWhere('slug', $id))->firstOrFail();
        $filterIds = $category->filter_attribute_ids ?: ProductAttribute::where('is_filterable', true)->pluck('id')->all();
        $filters = ProductAttribute::whereIn('id', $filterIds)->with('values')->orderBy('sort_order')->get()
            ->map(fn ($a) => ['attribute' => ['code' => $a->code, 'name' => $a->localized('name'), 'type' => $a->type],
                'values' => $a->values->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->localized('value'), 'color_hex' => $v->color_hex])]);

        return response()->json(['data' => [...$this->categoryNode($category), 'children' => $category->children->map(fn ($c) => $this->categoryNode($c)), 'filters' => $filters]]);
    }

    public function brands(): JsonResponse
    {
        return response()->json(['data' => Brand::where('status', 'active')->orderBy('sort_order')->orderBy('name_en')->get()
            ->map(fn ($b) => ['id' => $b->id, 'slug' => $b->slug, 'name' => $b->localized('name'), 'logo_url' => $this->url($b->logo_path)])]);
    }

    public function products(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => 'nullable|string|max:120', 'branch_id' => 'nullable|uuid', 'category_id' => 'nullable|uuid',
            'brand_id' => 'nullable|array', 'brand_id.*' => 'uuid', 'attribute_values' => 'nullable|array', 'attribute_values.*' => 'integer',
            'price_min' => 'nullable|numeric|min:0', 'price_max' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|boolean', 'on_sale' => 'nullable|boolean', 'featured' => 'nullable|boolean',
            'new_arrival' => 'nullable|boolean', 'bestseller' => 'nullable|boolean',
            'sort' => 'nullable|in:relevance,price,-price,-created_at,-rating,-popularity', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $branch = $this->branch($f['branch_id'] ?? null);
        $page = $this->query->build($f, $branch?->id)->paginate($this->perPage($request))->withQueryString();

        return response()->json([
            'data' => $this->presenter->cards($page->getCollection(), $branch),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:1|max:120']);

        return $this->products($request);
    }

    /** Search-as-you-type: products, categories, brands and a "did you mean" correction. */
    public function suggest(Request $request, SearchSuggester $suggester): JsonResponse
    {
        $f = $request->validate(['q' => 'required|string|min:2|max:80', 'branch_id' => 'nullable|uuid']);

        return response()->json(['data' => $suggester->suggest($f['q'], $this->branch($f['branch_id'] ?? null))]);
    }

    public function product(Request $request, string $idOrSlug): JsonResponse
    {
        $request->validate(['branch_id' => 'nullable|uuid']);
        $product = Product::active()
            ->where(fn ($q) => $q->whereKey(Str::isUuid($idOrSlug) ? $idOrSlug : null)
                ->orWhereHas('translations', fn ($t) => $t->where('slug', $idOrSlug)))
            ->firstOrFail();
        $branch = $this->branch($request->input('branch_id'));

        return response()->json(['data' => $this->presenter->detail($product, $branch)]);
    }

    /** Per-branch availability & price for each variant (stock shown capped at 10). */
    public function availability(Request $request, string $id, PriceResolver $prices): JsonResponse
    {
        $request->validate(['branch_id' => 'nullable|uuid']);
        $product = Product::active()->with(['variants' => fn ($q) => $q->where('status', 'active')->with('attributeValues')])->findOrFail($id);
        $branches = Branch::active()->with(['hours', 'holidays'])->when($request->input('branch_id'), fn ($q, $b) => $q->whereKey($b))->orderBy('sort_order')->get();
        $variants = $product->variants->each(fn ($v) => $v->setRelation('product', $product));
        $out = [];
        foreach ($branches as $branch) {
            $prices->prime($variants, $branch->id);
            $stock = $this->presenter->stockMap($variants->pluck('id')->all(), $branch->id);
            $rows = $variants->map(function ($v) use ($prices, $branch, $stock) {
                $price = $prices->resolve($v, $branch);

                return ['variant_id' => $v->id, 'name' => $v->displayName(), 'available_in_branch' => $price !== null,
                    'available' => $price ? min(10, max(0, $stock[$v->id] ?? 0)) : 0, 'in_stock' => $price !== null && ($stock[$v->id] ?? 0) > 0,
                    'price' => $price?->toArray()];
            })->values();
            if ($rows->contains('available_in_branch', true)) {
                $out[] = ['branch' => ['id' => $branch->id, 'name' => $branch->localized('name'), 'pickup_enabled' => $branch->pickup_enabled,
                    'delivery_enabled' => $branch->delivery_enabled], 'variants' => $rows];
            }
        }

        return response()->json(['data' => ['product_id' => $product->id, 'branches' => $out]]);
    }

    public function branchProducts(Request $request, string $branchId): JsonResponse
    {
        Branch::active()->findOrFail($branchId);
        $request->merge(['branch_id' => $branchId]);

        return $this->products($request);
    }

    private function branch(?string $id): ?Branch
    {
        return $id ? Branch::active()->find($id) : null;
    }

    private function categoryTree($nodes): array
    {
        return $nodes->map(fn ($c) => [...$this->categoryNode($c), 'children' => $this->categoryTree($c->children)])->values()->all();
    }

    private function categoryNode(Category $c): array
    {
        return ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->trans('name'), 'description' => $c->trans('description'),
            'image_url' => $this->url($c->image_path), 'icon_url' => $this->url($c->icon_path), 'banner_url' => $this->url($c->banner_path)];
    }

    private function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path);
    }
}
