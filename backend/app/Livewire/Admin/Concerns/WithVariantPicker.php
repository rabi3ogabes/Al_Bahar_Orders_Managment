<?php

namespace App\Livewire\Admin\Concerns;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Keyboard-first variant lookup for document lines (adjustments, transfers, purchase orders).
 * Type or scan a SKU/barcode and press Enter, or pick from the live results list.
 */
trait WithVariantPicker
{
    public string $variantSearch = '';

    abstract protected function onVariantPicked(ProductVariant $variant): void;

    /** Variants matching the picker search (max 8), with labels eager-loaded. */
    protected function variantMatches(): Collection
    {
        $term = trim($this->variantSearch);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return ProductVariant::query()->with(['product.translations', 'attributeValues'])
            ->whereHas('product', fn ($p) => $p->where('status', '!=', 'archived'))
            ->where(fn ($w) => $w->where('sku', 'ilike', $like)->orWhere('barcode', $term)
                ->orWhereHas('product.translations', fn ($t) => $t->where('name', 'ilike', $like)))
            ->orderBy('sku')->limit(8)->get();
    }

    public function pickVariantByCode(): void
    {
        $term = trim($this->variantSearch);
        if ($term === '') {
            return;
        }
        $variant = ProductVariant::where('sku', $term)->orWhere('barcode', $term)->first()
            ?? ProductVariant::whereRaw('upper(sku) = ?', [mb_strtoupper($term)])->first();
        if (! $variant) {
            $matches = $this->variantMatches();
            $variant = $matches->count() === 1 ? $matches->first() : null;
        }
        if (! $variant) {
            $this->toast(__('inventory_admin.variant_not_found', ['code' => $term]), 'warning');

            return;
        }
        $this->pickVariant($variant->id);
    }

    public function pickVariant(string $variantId): void
    {
        $this->onVariantPicked(ProductVariant::findOrFail($variantId));
        $this->variantSearch = '';
        $this->resetValidation('lines');
    }

    /** Load labelled variants for the given ids, keyed by id (one query). */
    protected function variantsFor(array $ids): Collection
    {
        return $ids ? ProductVariant::with(['product.translations', 'attributeValues'])->whereIn('id', $ids)->get()->keyBy('id') : collect();
    }
}
