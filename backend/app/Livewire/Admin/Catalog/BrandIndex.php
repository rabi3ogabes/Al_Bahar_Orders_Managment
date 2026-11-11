<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Brand;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class BrandIndex extends Component
{
    use StoresCatalogMedia, WithAdminTable, WithFileUploads;

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    public ?string $editingId = null;

    public array $form = [];

    public $logo = null;

    public function mount(): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->form = $this->blank();
    }

    private function blank(): array
    {
        return ['slug' => '', 'name_en' => '', 'name_ar' => '', 'description_en' => '', 'description_ar' => '', 'status' => 'active', 'sort_order' => 0, 'logo_path' => null];
    }

    public function create(): void
    {
        abort_unless($this->canGlobally('catalog.create'), 403);
        $this->resetValidation();
        $this->reset('logo', 'editingId');
        $this->form = $this->blank();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $b = Brand::findOrFail($id);
        $this->resetValidation();
        $this->reset('logo');
        $this->editingId = $b->id;
        $this->form = $b->only(array_keys($this->blank()));
        $this->form['description_en'] = (string) $b->description_en;
        $this->form['description_ar'] = (string) $b->description_ar;
        $this->showEditor = true;
    }

    public function updatedFormNameEn(string $value): void
    {
        if (! $this->editingId && $this->form['slug'] === '') {
            $this->form['slug'] = Str::slug($value);
        }
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canGlobally($this->editingId ? 'catalog.edit' : 'catalog.create'), 403);
        $data = $this->validate([
            'form.slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('brands', 'slug')->ignore($this->editingId)],
            'form.name_en' => 'required|string|max:120',
            'form.name_ar' => 'required|string|max:120',
            'form.description_en' => 'nullable|string|max:2000',
            'form.description_ar' => 'nullable|string|max:2000',
            'form.status' => 'required|in:active,inactive',
            'form.sort_order' => 'required|integer|min:0|max:100000',
            'logo' => self::imageRule(2048),
        ], [], ['form.slug' => __('catalog_admin.slug'), 'logo' => __('catalog_admin.logo')])['form'];
        $data = collect($data)->map(fn ($v) => $v === '' ? null : $v)->all();

        $brand = $this->editingId ? Brand::findOrFail($this->editingId) : new Brand;
        $oldLogo = $brand->logo_path;
        if ($this->logo) {
            $data['logo_path'] = $this->storeMedia($this->logo, 'brands');
        }
        $brand->fill($data);
        $dirty = $brand->getDirty();
        $old = array_intersect_key($brand->getOriginal(), $dirty);
        $brand->save();
        if ($this->logo && $oldLogo) {
            $this->deleteMedia($oldLogo);
        }
        $audit->log($this->editingId ? 'catalog.brand_updated' : 'catalog.brand_created', $brand, $old, $dirty);
        $this->reset('logo');
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function delete(string $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.delete'), 403);
        $b = Brand::withCount('products')->findOrFail($id);
        if ($b->products_count > 0) {
            $this->toast(__('catalog_admin.brand_in_use', ['count' => $b->products_count]), 'error');

            return;
        }
        $snapshot = $b->only(['id', 'slug', 'name_en']);
        $b->delete();
        $this->deleteMedia($b->logo_path);
        $audit->log('catalog.brand_deleted', 'Brand', $snapshot);
        $this->toast(__('catalog_admin.brand_deleted'));
    }

    public function render()
    {
        $s = $this->search;
        $brands = Brand::query()->withCount('products')
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")->orWhere('slug', 'ilike', "%{$s}%")))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->orderBy('sort_order')->orderBy('name_en');

        return view('livewire.admin.catalog.brands', [
            'brands' => $brands->paginate($this->perPage),
            'canCreate' => $this->canGlobally('catalog.create'),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'canDelete' => $this->canGlobally('catalog.delete'),
            'existingLogo' => $this->editingId ? self::mediaUrl($this->form['logo_path'] ?? null) : null,
        ])->title(__('nav.brands'));
    }
}
