<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\Services\PricingService;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product lifecycle. Every product has at least one variant (a "simple" product has exactly one
 * default variant), because inventory, cart lines and orders are always variant-based.
 */
class ProductService
{
    private const CORE = ['sku', 'barcode', 'category_id', 'brand_id', 'status', 'currency', 'cost_price', 'weight', 'weight_unit',
        'length', 'width', 'height', 'dimension_unit', 'is_featured', 'is_bestseller', 'is_new_arrival', 'requires_assembly',
        'assembly_service_available', 'is_oversized', 'available_in_all_branches', 'tags', 'specifications', 'allow_sale_above_price'];

    public function __construct(private readonly AuditLogger $audit, private readonly PricingService $pricing) {}

    /**
     * @param array{sku: string, price: string, sale_price?: ?string, translations: array<string, array>, variants?: list<array>, warranty?: ?array} $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $this->assertUniqueCodes($data['sku'] ?? null, $data['barcode'] ?? null);
            $product = new Product(collect($data)->only(self::CORE)->all());
            $product->price = $data['price'];
            $product->sale_price = $data['sale_price'] ?? null;
            $product->sale_starts_at = $data['sale_starts_at'] ?? null;
            $product->sale_ends_at = $data['sale_ends_at'] ?? null;
            $product->created_by = auth()->id();
            $product->published_at = ($data['status'] ?? 'draft') === 'active' ? now() : null;
            $product->has_variants = ! empty($data['variants']);
            $product->save();

            $this->syncTranslations($product, $data['translations']);
            if (! empty($data['variants'])) {
                foreach ($data['variants'] as $i => $v) {
                    $this->createVariant($product, $v + ['is_default' => $i === 0, 'sort_order' => $i]);
                }
            } else {
                $this->createVariant($product, ['sku' => $product->sku, 'barcode' => null, 'is_default' => true]);
            }
            if (! empty($data['warranty']['duration_months'])) {
                $product->warranty()->create($data['warranty']);
            }
            $this->audit->log('catalog.product_created', $product, [], ['sku' => $product->sku, 'price' => $product->price, 'status' => $product->status]);

            return $product->load('variants', 'translations');
        });
    }

    public function update(Product $product, array $data, ?string $reason = null): Product
    {
        return DB::transaction(function () use ($product, $data, $reason) {
            if (isset($data['sku']) && $data['sku'] !== $product->sku) {
                $this->assertUniqueCodes($data['sku'], null, $product->id);
            }
            if (array_key_exists('barcode', $data) && $data['barcode'] && $data['barcode'] !== $product->barcode) {
                $this->assertUniqueCodes(null, $data['barcode'], $product->id);
            }
            $product->fill(collect($data)->only(self::CORE)->all());
            if (($data['status'] ?? null) === 'active' && ! $product->published_at) {
                $product->published_at = now();
            }
            $product->updated_by = auth()->id();
            $dirty = $product->getDirty();
            $old = array_intersect_key($product->getOriginal(), $dirty);
            $product->save();

            if (array_key_exists('price', $data)) {
                $this->pricing->setProductPrice($product, $data['price'], $data['sale_price'] ?? null, $data['sale_starts_at'] ?? null, $data['sale_ends_at'] ?? null, $reason);
            }
            if (isset($data['translations'])) {
                $this->syncTranslations($product, $data['translations']);
            }
            if (array_key_exists('warranty', $data)) {
                empty($data['warranty']['duration_months'])
                    ? $product->warranty()->delete()
                    : $product->warranty()->updateOrCreate([], $data['warranty']);
            }
            if ($dirty) {
                $this->audit->log('catalog.product_updated', $product, $old, $dirty, ['reason' => $reason]);
            }

            return $product->refresh();
        });
    }

    public function createVariant(Product $product, array $v): ProductVariant
    {
        // A variant SKU must be unique across all variants, and may only equal its OWN product's SKU.
        if (ProductVariant::where('sku', $v['sku'])->exists() || Product::where('sku', $v['sku'])->whereKeyNot($product->id)->exists()) {
            throw DomainException::make('sku_taken');
        }
        if (! empty($v['barcode']) && (ProductVariant::where('barcode', $v['barcode'])->exists() || Product::where('barcode', $v['barcode'])->whereKeyNot($product->id)->exists())) {
            throw DomainException::make('barcode_taken');
        }
        if (! empty($v['is_default'])) {
            $product->variants()->update(['is_default' => false]);
        }
        $variant = $product->variants()->create(collect($v)->only([
            'sku', 'barcode', 'name_en', 'name_ar', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'cost_price',
            'weight', 'length', 'width', 'height', 'status', 'is_default', 'sort_order',
        ])->all());
        if (! empty($v['attribute_value_ids'])) {
            $variant->attributeValues()->sync($v['attribute_value_ids']);
        }
        if ($product->variants()->count() > 1 && ! $product->has_variants) {
            $product->forceFill(['has_variants' => true])->save();
        }

        return $variant;
    }

    public function updateVariant(ProductVariant $variant, array $v, ?string $reason = null): ProductVariant
    {
        return DB::transaction(function () use ($variant, $v, $reason) {
            if (isset($v['sku']) && $v['sku'] !== $variant->sku) {
                $this->assertUniqueCodes($v['sku'], null, null, $variant->product_id, $variant->id);
            }
            if (! empty($v['barcode']) && $v['barcode'] !== $variant->barcode) {
                $this->assertUniqueCodes(null, $v['barcode'], null, $variant->product_id, $variant->id);
            }
            $variant->fill(collect($v)->only(['sku', 'barcode', 'name_en', 'name_ar', 'cost_price', 'weight', 'length', 'width', 'height', 'status', 'sort_order'])->all());
            $variant->save();
            if (array_key_exists('price', $v)) {
                $this->pricing->setVariantPrice($variant, $v['price'], $v['sale_price'] ?? null, $v['sale_starts_at'] ?? null, $v['sale_ends_at'] ?? null, $reason);
            }
            if (isset($v['attribute_value_ids'])) {
                $variant->attributeValues()->sync($v['attribute_value_ids']);
            }
            if (! empty($v['is_default'])) {
                $variant->product->variants()->whereKeyNot($variant->id)->update(['is_default' => false]);
                $variant->forceFill(['is_default' => true])->save();
            }

            return $variant;
        });
    }

    public function archive(Product $product): void
    {
        $this->update($product, ['status' => 'archived']);
    }

    /** Bulk status/category/brand update — each product is audited individually. */
    public function bulkUpdate(array $productIds, array $changes): int
    {
        $changes = collect($changes)->only(['status', 'category_id', 'brand_id', 'is_featured', 'is_new_arrival', 'is_bestseller'])->filter(fn ($v) => $v !== null && $v !== '')->all();
        $count = 0;
        Product::whereIn('id', $productIds)->each(function (Product $p) use ($changes, &$count) {
            $this->update($p, $changes, 'bulk_update');
            $count++;
        });

        return $count;
    }

    /** Duplicate as a draft with suffixed SKUs; prices, translations, variants, specs and branch settings are copied. */
    public function duplicate(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product->load(['translations', 'variants.attributeValues', 'warranty', 'branchSettings', 'images']);
            $suffix = '-COPY-'.Str::upper(Str::random(4));
            $copy = $product->replicate(['rating_average', 'rating_count', 'sales_count', 'published_at']);
            $copy->sku = Str::limit($product->sku, 50, '').$suffix;
            $copy->barcode = null;
            $copy->status = 'draft';
            $copy->created_by = auth()->id();
            $copy->save();
            foreach ($product->translations as $t) {
                $copy->translations()->create(['locale' => $t->locale, 'name' => $t->name.($t->locale === 'ar' ? ' (نسخة)' : ' (Copy)'),
                    'slug' => Str::limit($t->slug, 180, '').'-'.Str::lower(Str::random(5)), 'short_description' => $t->short_description,
                    'description' => $t->description, 'material' => $t->material, 'seo_title' => $t->seo_title,
                    'seo_description' => $t->seo_description, 'search_keywords' => $t->search_keywords]);
            }
            foreach ($product->variants as $v) {
                $nv = $copy->variants()->create([...$v->only(['name_en', 'name_ar', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'cost_price', 'weight', 'length', 'width', 'height', 'status', 'is_default', 'sort_order']),
                    'sku' => Str::limit($v->sku, 50, '').$suffix, 'barcode' => null]);
                $nv->attributeValues()->sync($v->attributeValues->pluck('id'));
            }
            foreach ($product->branchSettings as $s) {
                $copy->branchSettings()->create($s->only(['branch_id', 'is_enabled', 'is_visible', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at']));
            }
            foreach ($product->images as $img) {
                $copy->images()->create($img->only(['disk', 'path', 'variants', 'alt_en', 'alt_ar', 'width', 'height', 'sort_order']));
            }
            if ($product->warranty) {
                $copy->warranty()->create($product->warranty->only(['duration_months', 'provider', 'terms_en', 'terms_ar']));
            }
            $this->audit->log('catalog.product_duplicated', $copy, [], ['source_id' => $product->id]);

            return $copy;
        });
    }

    public function syncTranslations(Product $product, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            if (! in_array($locale, config('platform.supported_locales'), true) || empty($t['name'])) {
                continue;
            }
            $existing = $product->translations()->where('locale', $locale)->first();
            $slug = $t['slug'] ?? $existing?->slug ?? $this->uniqueSlug($locale, $t['name']);
            $product->translations()->updateOrCreate(['locale' => $locale], [
                'name' => $t['name'], 'slug' => $slug,
                'short_description' => $t['short_description'] ?? null, 'description' => isset($t['description']) ? $this->sanitizeHtml($t['description']) : null,
                'material' => $t['material'] ?? null, 'seo_title' => $t['seo_title'] ?? null,
                'seo_description' => $t['seo_description'] ?? null, 'search_keywords' => $t['search_keywords'] ?? null,
            ]);
        }
        $product->unsetRelation('translations');
    }

    /** Allow-list sanitiser for rich product descriptions (no scripts, styles, event handlers or javascript: URLs). */
    public function sanitizeHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $clean = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><blockquote><a><span>');
        $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\s(style|class|id)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);

        return preg_replace('/href\s*=\s*(["\'])\s*(javascript|data|vbscript):[^"\']*\1/i', 'href="#"', $clean);
    }

    private function uniqueSlug(string $locale, string $name): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(8));
        if ($locale === 'ar') {
            $base = trim(preg_replace('/[^\p{Arabic}\p{N}]+/u', '-', $name), '-') ?: $base;
        }
        $slug = $base;
        $i = 2;
        while (\App\Domain\Catalog\Models\ProductTranslation::where('locale', $locale)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function assertUniqueCodes(?string $sku, ?string $barcode, ?string $ignoreProductId = null, ?string $ownerProductId = null, ?string $ignoreVariantId = null): void
    {
        if ($sku) {
            $productClash = Product::where('sku', $sku)->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))
                ->when($ownerProductId, fn ($q) => $q->whereKeyNot($ownerProductId))->exists();
            $variantClash = ProductVariant::where('sku', $sku)->when($ignoreVariantId, fn ($q) => $q->whereKeyNot($ignoreVariantId))
                ->when($ownerProductId, fn ($q) => $q->where('product_id', '!=', $ownerProductId))->exists();
            if ($productClash || $variantClash) {
                throw DomainException::make('sku_taken');
            }
        }
        if ($barcode) {
            $clash = Product::where('barcode', $barcode)->when($ignoreProductId, fn ($q) => $q->whereKeyNot($ignoreProductId))->exists()
                || ProductVariant::where('barcode', $barcode)->when($ignoreVariantId, fn ($q) => $q->whereKeyNot($ignoreVariantId))->exists();
            if ($clash) {
                throw DomainException::make('barcode_taken');
            }
        }
    }
}
