<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\MediaService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Product gallery: upload through MediaService (re-encoded, EXIF stripped, WebP sizes), drag to reorder, alt text EN/AR, variant link. */
class ProductMedia extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    public Product $product;

    public array $uploads = [];

    public string $uploadVariant = '';

    /** image id => [alt_en, alt_ar, product_variant_id] */
    public array $meta = [];

    public function mount(Product $product): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->product = $product;
        $this->loadMeta();
    }

    private function loadMeta(): void
    {
        $this->meta = $this->product->images()->get()->mapWithKeys(fn ($i) => [$i->id => [
            'alt_en' => (string) $i->alt_en, 'alt_ar' => (string) $i->alt_ar, 'product_variant_id' => (string) $i->product_variant_id,
        ]])->all();
    }

    public function updatedUploads(MediaService $media): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $max = (int) config('platform.media.max_image_kb');
        $this->validate(['uploads' => 'array|max:12', 'uploads.*' => 'image|mimes:jpg,jpeg,png,webp|max:'.$max], [], ['uploads.*' => __('catalog_admin.image')]);
        $variantId = $this->uploadVariant && $this->product->variants()->whereKey($this->uploadVariant)->exists() ? $this->uploadVariant : null;
        $altEn = $this->product->trans('name', 'en');
        $altAr = $this->product->trans('name', 'ar');
        $n = 0;
        foreach ($this->uploads as $file) {
            if ($this->attempt(fn () => $media->storeProductImage($this->product, $file, $variantId, $altEn, $altAr))) {
                $n++;
            }
        }
        $this->reset('uploads');
        $this->loadMeta();
        if ($n) {
            $this->toast(trans_choice('catalog_admin.images_uploaded', $n, ['count' => $n]));
        }
    }

    /** wire:sort handler — $id moved to $position. */
    public function sortImage(string $id, int $position, MediaService $media, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $ids = $this->product->images()->pluck('id')->all();
        if (! in_array($id, $ids, true)) {
            return;
        }
        $ids = array_values(array_diff($ids, [$id]));
        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);
        $media->reorder($this->product, $ids);
        $audit->log('catalog.images_reordered', $this->product, [], ['order' => $ids]);
    }

    public function saveMeta(string $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $this->validate([
            "meta.{$id}.alt_en" => 'nullable|string|max:255',
            "meta.{$id}.alt_ar" => 'nullable|string|max:255',
            "meta.{$id}.product_variant_id" => 'nullable|uuid',
        ]);
        $img = ProductImage::where('product_id', $this->product->id)->findOrFail($id);
        $m = $this->meta[$id];
        $variant = $m['product_variant_id'] && $this->product->variants()->whereKey($m['product_variant_id'])->exists() ? $m['product_variant_id'] : null;
        $img->fill(['alt_en' => $m['alt_en'] ?: null, 'alt_ar' => $m['alt_ar'] ?: null, 'product_variant_id' => $variant]);
        if ($img->isDirty()) {
            $dirty = $img->getDirty();
            $img->save();
            $audit->log('catalog.image_updated', $this->product, [], ['image_id' => $img->id] + $dirty);
            $this->toast(__('admin.saved'));
        }
    }

    public function delete(string $id, MediaService $media): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $img = ProductImage::where('product_id', $this->product->id)->findOrFail($id);
        $media->deleteImage($img);
        unset($this->meta[$id]);
        $this->toast(__('catalog_admin.image_deleted'));
    }

    public function render()
    {
        return view('livewire.admin.catalog.product-media', [
            'images' => $this->product->images()->get(),
            'variants' => $this->product->variants()->with('attributeValues')->get()->each(fn ($v) => $v->setRelation('product', $this->product)),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'maxKb' => (int) config('platform.media.max_image_kb'),
        ]);
    }
}
