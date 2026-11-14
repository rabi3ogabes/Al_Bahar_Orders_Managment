<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Services\ProductService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Rules\ProductBarcode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/** Attribute-driven variant builder plus per-variant SKU/barcode/status/default management. */
class ProductVariants extends Component
{
    use InteractsWithAdmin;

    public Product $product;

    /** attribute id => list of selected value ids */
    public array $picked = [];

    /** generated combinations: [values => int[], sku, barcode, include] */
    public array $generated = [];

    public bool $reuseDefault = true;

    public bool $showEditor = false;

    public array $edit = [];

    public function mount(Product $product): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->product = $product;
    }

    public function togglePick(int $attributeId, int $valueId): void
    {
        $current = $this->picked[$attributeId] ?? [];
        $this->picked[$attributeId] = in_array($valueId, $current, true) ? array_values(array_diff($current, [$valueId])) : [...$current, $valueId];
        if (! $this->picked[$attributeId]) {
            unset($this->picked[$attributeId]);
        }
        $this->generated = [];
    }

    public function generate(): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $axes = array_filter($this->picked);
        if (! $axes) {
            $this->toast(__('catalog_admin.pick_values_first'), 'warning');

            return;
        }
        $combos = [[]];
        foreach ($axes as $valueIds) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($valueIds as $vid) {
                    $next[] = [...$c, (int) $vid];
                }
            }
            $combos = $next;
        }
        $existing = $this->product->variants()->with('attributeValues')->get()
            ->map(fn ($v) => $v->attributeValues->pluck('id')->sort()->values()->implode('-'))->filter()->all();
        $values = ProductAttributeValue::whereIn('id', array_merge(...array_values($axes)))->get()->keyBy('id');
        $this->generated = [];
        foreach ($combos as $c) {
            sort($c);
            if (in_array(implode('-', $c), $existing, true)) {
                continue;
            }
            $suffix = collect($c)->map(fn ($id) => Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $values[$id]->code), 6, '')))->implode('-');
            $this->generated[] = ['values' => $c, 'sku' => Str::limit($this->product->sku, 40, '').'-'.$suffix, 'barcode' => '', 'include' => true];
        }
        if (! $this->generated) {
            $this->toast(__('catalog_admin.all_combinations_exist'), 'warning');
        }
    }

    public function createGenerated(ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $this->validate([
            'generated' => 'array|min:1|max:200',
            'generated.*.sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/', 'distinct'],
            'generated.*.barcode' => ['nullable', 'string', 'max:64', 'distinct:ignore_case', new ProductBarcode(ownerProductId: $this->product->id)],
            'generated.*.values' => 'array', 'generated.*.values.*' => 'integer|exists:product_attribute_values,id',
        ], [], ['generated.*.sku' => 'SKU', 'generated.*.barcode' => __('catalog_admin.barcode')]);
        $rows = array_values(array_filter($this->generated, fn ($g) => $g['include']));
        $variants = $this->product->variants()->with('attributeValues')->get();
        $bare = $variants->count() === 1 && $variants->first()->attributeValues->isEmpty() ? $variants->first() : null;

        $created = $this->attempt(fn () => DB::transaction(function () use ($rows, $products, $bare, $variants) {
            $n = 0;
            $sort = (int) $variants->max('sort_order');
            foreach ($rows as $i => $g) {
                if ($i === 0 && $bare && $this->reuseDefault) {
                    $products->updateVariant($bare->setRelation('product', $this->product), ['attribute_value_ids' => $g['values']]);
                } else {
                    $products->createVariant($this->product, ['sku' => $g['sku'], 'barcode' => $g['barcode'] ?: null, 'attribute_value_ids' => $g['values'],
                        'status' => 'active', 'sort_order' => ++$sort]);
                }
                $n++;
            }

            return $n;
        }));
        if ($created) {
            app(AuditLogger::class)->log('catalog.variants_generated', $this->product, [], ['count' => $created]);
            $this->product->refresh();
            $this->reset('generated', 'picked');
            $this->toast(trans_choice('catalog_admin.variants_created', $created, ['count' => $created]));
        }
    }

    public function openEdit(string $variantId): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $v = $this->product->variants()->with('attributeValues')->findOrFail($variantId);
        $this->resetValidation();
        $this->edit = array_map(fn ($x) => $x ?? '', $v->only(['id', 'sku', 'barcode', 'name_en', 'name_ar', 'status', 'is_default', 'weight', 'length', 'width', 'height']));
        $this->edit['values'] = $v->attributeValues->mapWithKeys(fn ($av) => [(string) $av->product_attribute_id => (string) $av->id])->all();
        $this->showEditor = true;
    }

    public function saveEdit(ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $v = $this->product->variants()->findOrFail($this->edit['id']);
        $data = $this->validate([
            'edit.sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'edit.barcode' => ['nullable', 'string', 'max:64', new ProductBarcode(ignoreVariantId: $v->id, ownerProductId: $this->product->id)],
            'edit.name_en' => 'nullable|string|max:255', 'edit.name_ar' => 'nullable|string|max:255',
            'edit.status' => 'required|in:active,inactive', 'edit.is_default' => 'boolean',
            'edit.weight' => 'nullable|numeric|min:0', 'edit.length' => 'nullable|numeric|min:0', 'edit.width' => 'nullable|numeric|min:0', 'edit.height' => 'nullable|numeric|min:0',
            'edit.values' => 'array', 'edit.values.*' => 'nullable|integer|exists:product_attribute_values,id',
        ], [], ['edit.sku' => 'SKU', 'edit.barcode' => __('catalog_admin.barcode')])['edit'];
        $payload = array_map(fn ($x) => $x === '' ? null : $x, collect($data)->except(['values', 'is_default'])->all());
        $payload['attribute_value_ids'] = array_values(array_map('intval', array_filter($data['values'] ?? [])));
        if ($data['is_default'] && ! $v->is_default) {
            $payload['is_default'] = true;
        }
        if ($v->is_default && $data['status'] === 'inactive' && $this->product->variants()->where('status', 'active')->count() <= 1) {
            $this->addError('edit.status', __('catalog_admin.last_active_variant'));

            return;
        }
        if ($this->attempt(fn () => $products->updateVariant($v->setRelation('product', $this->product), $payload), __('admin.saved'))) {
            $this->showEditor = false;
        }
    }

    public function toggleStatus(string $variantId, ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $v = $this->product->variants()->findOrFail($variantId);
        $this->attempt(fn () => $products->updateVariant($v->setRelation('product', $this->product), ['status' => $v->status === 'active' ? 'inactive' : 'active']), __('admin.saved'));
    }

    public function makeDefault(string $variantId, ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $v = $this->product->variants()->findOrFail($variantId);
        $this->attempt(fn () => $products->updateVariant($v->setRelation('product', $this->product), ['is_default' => true]), __('catalog_admin.default_set'));
    }

    public function render()
    {
        $attributes = ProductAttribute::with('values')->where('is_variant_axis', true)->orderBy('sort_order')->get();
        $variants = $this->product->variants()->with(['attributeValues'])->get();
        $valueLabels = $attributes->flatMap(fn ($a) => $a->values)->keyBy('id');

        return view('livewire.admin.catalog.product-variants', [
            'attributes' => $attributes,
            'variants' => $variants,
            'valueLabels' => $valueLabels,
            'canEdit' => $this->canGlobally('catalog.edit'),
            'bareDefault' => $variants->count() === 1 && $variants->first()->attributeValues->isEmpty(),
        ]);
    }
}
