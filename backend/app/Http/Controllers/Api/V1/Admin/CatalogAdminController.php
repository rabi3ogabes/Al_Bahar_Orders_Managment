<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\CatalogImport;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CatalogImportService;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\MediaService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Pricing\Services\PricingService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central catalog administration. Catalog content is company-wide (catalog.* permissions);
 * branch price/availability overrides additionally require pricing.edit on THAT branch.
 *
 * @tags Admin · Catalog
 */
class CatalogAdminController extends ApiController
{
    public function __construct(private readonly ProductService $products, private readonly PricingService $pricing) {}

    public function index(Request $request): JsonResponse
    {
        $q = Product::with(['translations', 'brand', 'category.translations'])->withCount('variants')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->query('brand_id'), fn ($q, $b) => $q->where('brand_id', $b))
            ->when($request->query('search'), fn ($q, $s) => app(ProductSearch::class)->apply($q, $s, false))
            ->orderBy($request->query('sort') === 'sku' ? 'sku' : 'updated_at', $request->query('sort') === 'sku' ? 'asc' : 'desc');
        $page = $q->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name_en' => $p->trans('name', 'en'), 'name_ar' => $p->trans('name', 'ar'),
                'status' => $p->status, 'price' => $p->price, 'sale_price' => $p->sale_price, 'variants_count' => $p->variants_count,
                'brand' => $p->brand?->name_en, 'category' => $p->category?->trans('name'), 'updated_at' => $p->updated_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated());

        return response()->json(['data' => $this->adminView($product)], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->adminView($product)]);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->products->update($product, $request->validated(), $request->input('reason'));

        return response()->json(['data' => $this->adminView($product)]);
    }

    public function archive(Product $product): JsonResponse
    {
        $this->products->archive($product);

        return response()->json(['data' => ['status' => 'archived']]);
    }

    public function duplicate(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->adminView($this->products->duplicate($product))], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(['product_ids' => 'required|array|max:500', 'product_ids.*' => 'uuid',
            'status' => 'nullable|in:draft,active,inactive,archived', 'category_id' => 'nullable|uuid|exists:categories,id', 'brand_id' => 'nullable|uuid|exists:brands,id']);

        return response()->json(['data' => ['updated' => $this->products->bulkUpdate($data['product_ids'], $data)]]);
    }

    public function storeVariant(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'sku' => 'required|string|max:64|regex:/^[A-Za-z0-9\-_.]+$/', 'barcode' => 'nullable|string|max:64',
            'name_en' => 'nullable|string|max:255', 'name_ar' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0', 'sale_price' => 'nullable|numeric|min:0|required_with:price|lte:price',
            'attribute_value_ids' => 'nullable|array', 'attribute_value_ids.*' => 'integer|exists:product_attribute_values,id',
        ]);

        return response()->json(['data' => $this->products->createVariant($product, $data)], 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validate([
            'sku' => 'sometimes|string|max:64', 'barcode' => 'nullable|string|max:64', 'status' => 'sometimes|in:active,inactive',
            'name_en' => 'nullable|string|max:255', 'name_ar' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0', 'sale_price' => 'nullable|numeric|min:0', 'is_default' => 'sometimes|boolean',
            'attribute_value_ids' => 'sometimes|array', 'attribute_value_ids.*' => 'integer|exists:product_attribute_values,id',
        ]);

        return response()->json(['data' => $this->products->updateVariant($variant, $data, $request->input('reason'))]);
    }

    /** Branch-level price & availability override (product or variant). */
    public function branchSettings(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,id',
            'variant_id' => 'nullable|uuid|exists:product_variants,id',
            'is_enabled' => 'sometimes|boolean', 'is_visible' => 'sometimes|boolean',
            'price' => 'nullable|numeric|min:0', 'sale_price' => 'nullable|numeric|min:0',
            'sale_starts_at' => 'nullable|date', 'sale_ends_at' => 'nullable|date|after:sale_starts_at',
            'reason' => 'nullable|string|max:255',
        ]);
        $this->authorizeBranch('pricing.edit', $data['branch_id']);
        $variant = isset($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : null;

        if ($request->has('is_enabled') || $request->has('is_visible')) {
            $variant
                ? $this->pricing->setVariantBranchAvailability($variant, $data['branch_id'], (bool) ($data['is_enabled'] ?? true))
                : $this->pricing->setBranchAvailability($product, $data['branch_id'], (bool) ($data['is_enabled'] ?? true), (bool) ($data['is_visible'] ?? true));
        }
        if ($request->has('price') || $request->has('sale_price')) {
            $args = [$data['branch_id'], $data['price'] ?? null, $data['sale_price'] ?? null, $data['sale_starts_at'] ?? null, $data['sale_ends_at'] ?? null, $data['reason'] ?? null];
            $variant ? $this->pricing->setVariantBranchPrice($variant, ...$args) : $this->pricing->setProductBranchPrice($product, ...$args);
        }

        return response()->json(['data' => $this->effectivePrices($product)]);
    }

    /** Effective price per branch for every variant — what customers will be charged. */
    public function effectivePrices(Product $product): array
    {
        $resolver = new PriceResolver;
        $variants = $product->variants()->get()->each(fn ($v) => $v->setRelation('product', $product));
        $scope = $this->scope('pricing.view');

        return Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get()
            ->map(function ($b) use ($resolver, $variants) {
                $resolver->prime($variants, $b->id);

                return ['branch_id' => $b->id, 'branch' => $b->name_en, 'variants' => $variants->map(fn ($v) => [
                    'variant_id' => $v->id, 'sku' => $v->sku,
                    'availability' => $resolver->availability($v, $b)->reason ?? 'available',
                    'price' => $resolver->resolve($v, $b)?->toArray(),
                ])];
            })->all();
    }

    public function priceHistory(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->priceHistory()->with('actor:id,name')->limit(200)->get()]);
    }

    public function uploadImage(Request $request, Product $product, MediaService $media): JsonResponse
    {
        $request->validate(['image' => 'required|file|max:'.config('platform.media.max_image_kb'), 'variant_id' => 'nullable|uuid',
            'alt_en' => 'nullable|string|max:255', 'alt_ar' => 'nullable|string|max:255']);
        $img = $media->storeProductImage($product, $request->file('image'), $request->input('variant_id'), $request->input('alt_en'), $request->input('alt_ar'));

        return response()->json(['data' => ['id' => $img->id, 'url' => $img->url(), 'thumb_url' => $img->url(640)]], 201);
    }

    public function reorderImages(Request $request, Product $product, MediaService $media): JsonResponse
    {
        $media->reorder($product, $request->validate(['ids' => 'required|array', 'ids.*' => 'uuid'])['ids']);

        return response()->json(['data' => ['reordered' => true]]);
    }

    public function deleteImage(ProductImage $image, MediaService $media): JsonResponse
    {
        $media->deleteImage($image);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function import(Request $request, CatalogImportService $imports): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:20480']);
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $import = CatalogImport::create(['user_id' => $this->user()->id, 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName()]);
        // Files within the API upload limit are processed inline so the caller gets the full row-level error report.
        $imports->process($import);

        return response()->json(['data' => $import->refresh()], 201);
    }

    public function storeCategory(Request $request, CategoryService $categories): JsonResponse
    {
        $data = $request->validate($this->categoryRules());

        return response()->json(['data' => $categories->create($data)->load('translations')], 201);
    }

    public function updateCategory(Request $request, Category $category, CategoryService $categories): JsonResponse
    {
        $data = $request->validate($this->categoryRules(true));

        return response()->json(['data' => $categories->update($category, $data)->load('translations')]);
    }

    public function destroyCategory(Category $category, CategoryService $categories): JsonResponse
    {
        $categories->delete($category);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => 'required|alpha_dash|max:160|unique:brands,slug', 'name_en' => 'required|string|max:120',
            'name_ar' => 'required|string|max:120', 'status' => 'sometimes|in:active,inactive', 'logo_path' => 'nullable|string']);
        $brand = Brand::create($data);
        app(AuditLogger::class)->log('catalog.brand_created', $brand);

        return response()->json(['data' => $brand], 201);
    }

    private function categoryRules(bool $partial = false): array
    {
        return [
            'parent_id' => 'nullable|uuid|exists:categories,id',
            'slug' => ($partial ? 'sometimes' : 'nullable').'|alpha_dash|max:160',
            'sort_order' => 'sometimes|integer|min:0', 'status' => 'sometimes|in:active,inactive',
            'filter_attribute_ids' => 'nullable|array', 'filter_attribute_ids.*' => 'integer|exists:product_attributes,id',
            'translations' => ($partial ? 'sometimes' : 'required').'|array',
            'translations.en.name' => ($partial ? 'sometimes' : 'required').'|string|max:120',
            'translations.ar.name' => ($partial ? 'sometimes' : 'required').'|string|max:120',
            'translations.*.description' => 'nullable|string|max:2000',
        ];
    }

    private function adminView(Product $p): array
    {
        $p->load(['translations', 'variants.attributeValues', 'warranty', 'branchSettings', 'images']);

        return [...$p->toArray(), 'effective_prices' => $this->effectivePrices($p)];
    }
}
