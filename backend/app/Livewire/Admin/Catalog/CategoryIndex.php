<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Services\CategoryService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Unlimited-depth category tree with ordering, re-parenting (CategoryService::move), artwork and filter attributes. */
class CategoryIndex extends Component
{
    use InteractsWithAdmin, StoresCatalogMedia, WithFileUploads;

    #[Url(except: '')]
    public string $search = '';

    public bool $showEditor = false;

    public ?string $editingId = null;

    public array $form = [];

    public $image = null;

    public $icon = null;

    public $banner = null;

    /** @var list<string> collapsed category ids */
    public array $collapsed = [];

    public function mount(): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->form = $this->blank();
    }

    private function blank(?string $parentId = null): array
    {
        return ['parent_id' => $parentId ?? '', 'slug' => '', 'name_en' => '', 'name_ar' => '', 'description_en' => '', 'description_ar' => '',
            'seo_title_en' => '', 'seo_title_ar' => '', 'status' => 'active', 'filter_attribute_ids' => []];
    }

    public function toggleCollapse(string $id): void
    {
        $this->collapsed = in_array($id, $this->collapsed, true) ? array_values(array_diff($this->collapsed, [$id])) : [...$this->collapsed, $id];
    }

    public function create(?string $parentId = null): void
    {
        abort_unless($this->canGlobally('catalog.create'), 403);
        $this->resetValidation();
        $this->reset('image', 'icon', 'banner', 'editingId');
        $this->form = $this->blank($parentId);
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $c = Category::with('translations')->findOrFail($id);
        $this->resetValidation();
        $this->reset('image', 'icon', 'banner');
        $this->editingId = $c->id;
        $en = $c->translations->firstWhere('locale', 'en');
        $ar = $c->translations->firstWhere('locale', 'ar');
        $this->form = [
            'parent_id' => (string) $c->parent_id, 'slug' => $c->slug, 'status' => $c->status,
            'name_en' => (string) $en?->name, 'name_ar' => (string) $ar?->name,
            'description_en' => (string) $en?->description, 'description_ar' => (string) $ar?->description,
            'seo_title_en' => (string) $en?->seo_title, 'seo_title_ar' => (string) $ar?->seo_title,
            'filter_attribute_ids' => array_map('intval', $c->filter_attribute_ids ?? []),
        ];
        $this->showEditor = true;
    }

    public function save(CategoryService $svc): void
    {
        abort_unless($this->canGlobally($this->editingId ? 'catalog.edit' : 'catalog.create'), 403);
        $d = $this->validate([
            'form.parent_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'form.slug' => [$this->editingId ? 'required' : 'nullable', 'alpha_dash', 'max:160', Rule::unique('categories', 'slug')->ignore($this->editingId)],
            'form.name_en' => 'required|string|max:120',
            'form.name_ar' => 'required|string|max:120',
            'form.description_en' => 'nullable|string|max:2000',
            'form.description_ar' => 'nullable|string|max:2000',
            'form.seo_title_en' => 'nullable|string|max:255',
            'form.seo_title_ar' => 'nullable|string|max:255',
            'form.status' => 'required|in:active,inactive',
            'form.filter_attribute_ids' => 'array',
            'form.filter_attribute_ids.*' => 'integer|exists:product_attributes,id',
            'image' => self::imageRule(), 'icon' => self::imageRule(1024), 'banner' => self::imageRule(6144),
        ], [], ['form.slug' => __('catalog_admin.slug'), 'form.parent_id' => __('catalog_admin.parent')])['form'];

        $payload = [
            'parent_id' => $d['parent_id'] ?: null,
            'status' => $d['status'],
            'filter_attribute_ids' => array_values(array_map('intval', $d['filter_attribute_ids'])) ?: null,
            'translations' => [
                'en' => ['name' => $d['name_en'], 'description' => $d['description_en'] ?: null, 'seo_title' => $d['seo_title_en'] ?: null],
                'ar' => ['name' => $d['name_ar'], 'description' => $d['description_ar'] ?: null, 'seo_title' => $d['seo_title_ar'] ?: null],
            ],
        ];
        if ($d['slug']) {
            $payload['slug'] = $d['slug'];
        }
        $existing = $this->editingId ? Category::findOrFail($this->editingId) : null;
        $replaced = [];
        foreach (['image' => 'image_path', 'icon' => 'icon_path', 'banner' => 'banner_path'] as $prop => $col) {
            if ($this->{$prop}) {
                $payload[$col] = $this->storeMedia($this->{$prop}, 'categories');
                $replaced[] = $existing?->{$col};
            }
        }
        if (! $existing) {
            $payload['sort_order'] = (int) Category::where('parent_id', $payload['parent_id'])->max('sort_order') + 1;
        }

        $ok = $this->attempt(fn () => $existing ? $svc->update($existing, $payload) : $svc->create($payload));
        if (! $ok) {
            return;
        }
        foreach ($replaced as $old) {
            $this->deleteMedia($old);
        }
        $this->reset('image', 'icon', 'banner');
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function removeArtwork(string $column, CategoryService $svc): void
    {
        abort_unless($this->canGlobally('catalog.edit') && in_array($column, ['image_path', 'icon_path', 'banner_path'], true), 403);
        $c = Category::findOrFail($this->editingId);
        $old = $c->{$column};
        $svc->update($c, [$column => null]);
        $this->deleteMedia($old);
    }

    public function move(string $id, int $direction, CategoryService $svc): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $c = Category::findOrFail($id);
        $siblings = Category::where('parent_id', $c->parent_id)->orderBy('sort_order')->orderBy('created_at')->get()->values();
        $i = $siblings->search(fn ($s) => $s->id === $c->id);
        $j = $i + ($direction < 0 ? -1 : 1);
        if ($i === false || ! isset($siblings[$j])) {
            return;
        }
        $ordered = $siblings->all();
        [$ordered[$i], $ordered[$j]] = [$ordered[$j], $ordered[$i]];
        foreach ($ordered as $pos => $s) {
            if ($s->sort_order !== $pos) {
                $svc->update($s, ['sort_order' => $pos]);
            }
        }
    }

    public function delete(string $id, CategoryService $svc): void
    {
        abort_unless($this->canGlobally('catalog.delete'), 403);
        $c = Category::findOrFail($id);
        $paths = [$c->image_path, $c->icon_path, $c->banner_path];
        if ($this->attempt(fn () => $svc->delete($c), __('catalog_admin.category_deleted'))) {
            array_map(fn ($p) => $this->deleteMedia($p), $paths);
        }
    }

    /** All categories in tree (depth-first) order with translations loaded — for parent/category selects. */
    public static function treeOptions(): Collection
    {
        $all = Category::with('translations')->orderBy('sort_order')->orderBy('created_at')->get();
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 'root');
        $out = collect();
        $walk = function ($key) use (&$walk, $byParent, $out) {
            foreach ($byParent[$key] ?? [] as $c) {
                $out->push($c);
                $walk($c->id);
            }
        };
        $walk('root');

        return $out;
    }

    /** Depth-first ordered list of [category, depth, hasChildren]. */
    private function flatten(Collection $all, bool $respectCollapse = true): Collection
    {
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 'root');
        $out = collect();
        $walk = function ($parentKey, bool $hidden) use (&$walk, $byParent, $out, $respectCollapse) {
            foreach ($byParent[$parentKey] ?? [] as $c) {
                $hasChildren = isset($byParent[$c->id]);
                if (! $hidden) {
                    $out->push(['c' => $c, 'children' => $hasChildren, 'siblings' => count($byParent[$parentKey])]);
                }
                $walk($c->id, $hidden || ($respectCollapse && in_array($c->id, $this->collapsed, true)));
            }
        };
        $walk('root', false);

        return $out;
    }

    public function render()
    {
        $all = Category::with('translations')->withCount('products')->orderBy('sort_order')->orderBy('created_at')->get();
        if ($s = mb_strtolower(trim($this->search))) {
            $rows = $all->filter(fn ($c) => str_contains(mb_strtolower($c->slug), $s) || $c->translations->contains(fn ($t) => str_contains(mb_strtolower($t->name), $s)))
                ->map(fn ($c) => ['c' => $c, 'children' => false, 'siblings' => 1])->values();
        } else {
            $rows = $this->flatten($all);
        }
        $excluded = [];
        if ($this->editingId && ($editing = $all->firstWhere('id', $this->editingId))) {
            $excluded = $all->filter(fn ($c) => $c->id === $editing->id || str_contains($c->path, '/'.$editing->id.'/'))->pluck('id')->all();
        }
        $editing = $this->editingId ? $all->firstWhere('id', $this->editingId) : null;

        return view('livewire.admin.catalog.categories', [
            'rows' => $rows,
            'total' => $all->count(),
            'parentOptions' => $this->flatten($all->reject(fn ($c) => in_array($c->id, $excluded, true)), false)->pluck('c'),
            'attributes' => ProductAttribute::where('is_filterable', true)->orderBy('sort_order')->get(),
            'editing' => $editing,
            'canCreate' => $this->canGlobally('catalog.create'),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'canDelete' => $this->canGlobally('catalog.delete'),
        ])->title(__('nav.categories'));
    }
}
