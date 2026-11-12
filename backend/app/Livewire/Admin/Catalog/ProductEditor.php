<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceHistory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductAi;
use App\Domain\Catalog\Services\ProductService;
use App\Http\Requests\Admin\ProductRequest;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Product workspace. Content and specifications are edited here; pricing, variants and media live in child
 * components (ProductPricing, ProductVariants, ProductMedia) so each tab stays light.
 */
class ProductEditor extends Component
{
    use InteractsWithAdmin;

    public ?Product $product = null;

    #[Url]
    public string $tab = 'content';

    public array $form = [];

    public array $tr = ['en' => [], 'ar' => []];

    public array $specs = [];

    public array $warranty = ['duration_months' => '', 'provider' => '', 'terms_en' => '', 'terms_ar' => ''];

    public string $tagsInput = '';

    public function mount(?Product $product = null): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $blankTr = ['name' => '', 'slug' => '', 'short_description' => '', 'description' => '', 'material' => '', 'seo_title' => '', 'seo_description' => '', 'search_keywords' => ''];
        if ($product?->exists) {
            $this->product = $product->load(['translations', 'warranty']);
            $this->form = $product->only(['sku', 'barcode', 'category_id', 'brand_id', 'status', 'cost_price', 'weight', 'weight_unit', 'length', 'width', 'height',
                'dimension_unit', 'is_featured', 'is_bestseller', 'is_new_arrival', 'requires_assembly', 'assembly_service_available', 'is_oversized',
                'available_in_all_branches', 'allow_sale_above_price']);
            $this->form = array_map(fn ($v) => $v ?? '', $this->form);
            foreach (['en', 'ar'] as $l) {
                $t = $product->translations->firstWhere('locale', $l);
                $this->tr[$l] = $t ? array_map(fn ($v) => (string) $v, $t->only(array_keys($blankTr))) : $blankTr;
            }
            $this->specs = array_values($product->specifications ?? []);
            $this->tagsInput = implode(', ', $product->tags ?? []);
            if ($product->warranty) {
                $this->warranty = array_map(fn ($v) => (string) $v, $product->warranty->only(['duration_months', 'provider', 'terms_en', 'terms_ar']));
            }
            if (! in_array($this->tab, ['content', 'pricing', 'variants', 'media', 'specs', 'history'], true)) {
                $this->tab = 'content';
            }
        } else {
            abort_unless($this->canGlobally('catalog.create'), 403);
            $this->form = ['sku' => '', 'barcode' => '', 'category_id' => '', 'brand_id' => '', 'status' => 'draft', 'price' => '', 'sale_price' => ''];
            $this->tr = ['en' => $blankTr, 'ar' => $blankTr];
        }
    }

    private function nullable(array $data): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    private function translationRules(): array
    {
        $base = ProductRequest::rulesFor(true);
        $rules = [];
        foreach (['en', 'ar'] as $l) {
            $rules["tr.{$l}.name"] = ['required', 'string', 'max:255'];
            $rules["tr.{$l}.slug"] = ['nullable', 'string', 'max:200', Rule::unique('product_translations', 'slug')->where('locale', $l)->ignore($this->product?->id, 'product_id')];
            foreach (['short_description', 'description', 'material', 'seo_title', 'seo_description', 'search_keywords'] as $f) {
                $rules["tr.{$l}.{$f}"] = $base["translations.*.{$f}"];
            }
        }

        return $rules;
    }

    private function attributeNames(): array
    {
        $names = ['form.sku' => 'SKU', 'form.barcode' => __('catalog_admin.barcode'), 'form.price' => __('catalog_admin.price'), 'form.sale_price' => __('catalog_admin.sale_price')];
        foreach (['en' => __('catalog_admin.lang_en'), 'ar' => __('catalog_admin.lang_ar')] as $l => $label) {
            foreach (['name', 'slug', 'short_description', 'description', 'seo_title', 'seo_description', 'search_keywords', 'material'] as $f) {
                $names["tr.{$l}.{$f}"] = __('catalog_admin.f_'.$f).' ('.$label.')';
            }
        }

        return $names;
    }

    public function create(ProductService $products)
    {
        abort_unless($this->canGlobally('catalog.create'), 403);
        $base = ProductRequest::rulesFor();
        $rules = ['form.sku' => $base['sku'], 'form.barcode' => $base['barcode'], 'form.category_id' => $base['category_id'], 'form.brand_id' => $base['brand_id'],
            'form.status' => ['required', 'in:draft,active,inactive'], 'form.price' => $base['price'], 'form.sale_price' => ['nullable', 'numeric', 'min:0', 'lte:form.price']] + $this->translationRules();
        $this->validate($rules, [], $this->attributeNames());

        $data = $this->nullable($this->form);
        $data['price'] = Money::of((string) $data['price'])->toDecimal();
        $data['sale_price'] = $data['sale_price'] !== null ? Money::of((string) $data['sale_price'])->toDecimal() : null;
        $data['translations'] = ['en' => $this->nullable($this->tr['en']), 'ar' => $this->nullable($this->tr['ar'])];
        $product = $this->attempt(fn () => $products->create($data));
        if ($product instanceof Product) {
            session()->flash('toast', ['type' => 'success', 'message' => __('catalog_admin.product_created')]);

            return $this->redirectRoute('admin.products.edit', ['product' => $product, 'tab' => 'media'], navigate: true);
        }
    }

    public function saveContent(ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $base = ProductRequest::rulesFor(true);
        $this->validate([
            'form.sku' => ['required', ...array_slice($base['sku'], 1)], 'form.barcode' => $base['barcode'],
            'form.category_id' => $base['category_id'], 'form.brand_id' => $base['brand_id'], 'form.status' => ['required', 'in:draft,active,inactive,archived'],
            'tagsInput' => 'nullable|string|max:1000',
        ] + $this->translationRules(), [], $this->attributeNames());
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 40), explode(',', $this->tagsInput)))));
        $data = $this->nullable(collect($this->form)->only(['sku', 'barcode', 'category_id', 'brand_id', 'status'])->all());
        $data['tags'] = $tags ?: null;
        $data['translations'] = ['en' => $this->nullable($this->tr['en']), 'ar' => $this->nullable($this->tr['ar'])];
        if ($this->attempt(fn () => $products->update($this->product, $data), __('admin.saved'))) {
            $this->product->refresh()->load('translations');
            foreach (['en', 'ar'] as $l) {
                $this->tr[$l]['slug'] = (string) $this->product->translations->firstWhere('locale', $l)?->slug;
            }
        }
    }

    public function addSpec(): void
    {
        $this->specs[] = ['label_en' => '', 'label_ar' => '', 'value_en' => '', 'value_ar' => ''];
    }

    public function removeSpec(int $i): void
    {
        unset($this->specs[$i]);
        $this->specs = array_values($this->specs);
    }

    public function moveSpec(int $i, int $dir): void
    {
        $j = $i + ($dir < 0 ? -1 : 1);
        if (isset($this->specs[$i], $this->specs[$j])) {
            [$this->specs[$i], $this->specs[$j]] = [$this->specs[$j], $this->specs[$i]];
        }
    }

    public function saveSpecs(ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $base = ProductRequest::rulesFor(true);
        $rules = [
            'specs' => 'array|max:60',
            'specs.*.label_en' => 'required|string|max:80', 'specs.*.label_ar' => 'required|string|max:80',
            'specs.*.value_en' => 'required|string|max:255', 'specs.*.value_ar' => 'required|string|max:255',
            'warranty.duration_months' => $base['warranty.duration_months'], 'warranty.provider' => $base['warranty.provider'],
            'warranty.terms_en' => $base['warranty.terms_en'], 'warranty.terms_ar' => $base['warranty.terms_ar'],
        ];
        foreach (['cost_price', 'weight', 'length', 'width', 'height'] as $f) {
            $rules["form.{$f}"] = $base[$f];
        }
        $rules['form.weight_unit'] = 'required|in:kg,g';
        $rules['form.dimension_unit'] = 'required|in:cm,m,mm';
        foreach (['is_featured', 'is_bestseller', 'is_new_arrival', 'requires_assembly', 'assembly_service_available', 'is_oversized', 'available_in_all_branches', 'allow_sale_above_price'] as $f) {
            $rules["form.{$f}"] = 'boolean';
        }
        $this->validate($rules, [], ['warranty.duration_months' => __('catalog_admin.warranty_months')]);

        $data = $this->nullable(collect($this->form)->only(['cost_price', 'weight', 'weight_unit', 'length', 'width', 'height', 'dimension_unit'])->all());
        foreach (['is_featured', 'is_bestseller', 'is_new_arrival', 'requires_assembly', 'assembly_service_available', 'is_oversized', 'available_in_all_branches', 'allow_sale_above_price'] as $f) {
            $data[$f] = (bool) $this->form[$f];
        }
        $data['specifications'] = $this->specs ? array_values($this->specs) : null;
        $data['warranty'] = $this->warranty['duration_months'] !== '' ? $this->nullable($this->warranty) : null;
        $this->attempt(fn () => $products->update($this->product, $data), __('admin.saved'));
    }

    /** Fills the short and long description (EN + AR) from the title with Claude. Nothing is saved until the form is. */
    public function describeWithAi(ProductAi $ai): void
    {
        abort_unless($this->canGlobally($this->product ? 'catalog.edit' : 'catalog.create'), 403);
        $category = ($this->form['category_id'] ?? null) ? Category::find($this->form['category_id'])?->trans('name', 'en') : null;
        $out = $this->attempt(fn () => $ai->describe($this->tr['en']['name'] ?? null, $this->tr['ar']['name'] ?? null, $category, $this->tr['en']['material'] ?? null));
        if (! $out) {
            return;
        }
        foreach (['en', 'ar'] as $l) {
            $this->tr[$l]['short_description'] = $out[$l]['short'];
            $this->tr[$l]['description'] = $out[$l]['description'];
        }
        $this->dispatch('ai-description-ready');
        $this->toast(__('catalog_admin.ai_written'));
    }

    public function render()
    {
        $data = [
            'aiReady' => app(ProductAi::class)->configured(),
            'categories' => CategoryIndex::treeOptions(),
            'brands' => Brand::orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'canEdit' => $this->canGlobally($this->product ? 'catalog.edit' : 'catalog.create'),
        ];
        if ($this->product && $this->tab === 'history') {
            $data['priceHistory'] = ProductPriceHistory::with(['actor:id,name'])->where('product_id', $this->product->id)->latest('created_at')->limit(100)->get();
            $data['historyBranches'] = Branch::whereIn('id', $data['priceHistory']->pluck('branch_id')->filter()->unique())->pluck('code', 'id');
            $data['historyVariants'] = ProductVariant::whereIn('id', $data['priceHistory']->pluck('product_variant_id')->filter()->unique())->pluck('sku', 'id');
            $data['auditLog'] = AuditLog::with('actor:id,name')->where('auditable_type', 'Product')->where('auditable_id', $this->product->id)->latest('id')->limit(50)->get();
        }
        if ($this->product) {
            $data['counts'] = [
                'variants' => $this->product->variants()->count(),
                'media' => $this->product->images()->count(),
            ];
        }

        return view('livewire.admin.catalog.product-editor', $data)->title($this->product ? ($this->product->trans('name') ?? $this->product->sku) : __('catalog_admin.new_product'));
    }
}
