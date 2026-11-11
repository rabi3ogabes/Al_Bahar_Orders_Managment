<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomepageService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Homepage builder: ordered sections (drag or move up/down), publish/hide, schedule window, EN/AR titles, layout and
 * hand-picked items, with a live preview rendered by HomepageService (exactly what the app receives).
 */
class HomepageIndex extends Component
{
    use InteractsWithAdmin;

    public const TYPES = ['banners', 'categories', 'featured_products', 'new_arrivals', 'best_sellers', 'recommended', 'product_collection', 'brands', 'promotions', 'upcoming_auctions', 'live_auctions'];

    public const LAYOUTS = ['carousel', 'grid', 'hero', 'chips'];

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    public array $form = [];

    public array $itemIds = [];

    public string $itemSearch = '';

    public string $previewLocale = 'en';

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->editingId = null;
        $this->form = ['type' => 'featured_products', 'title_en' => '', 'title_ar' => '', 'subtitle_en' => '', 'subtitle_ar' => '', 'layout' => 'carousel', 'item_limit' => 12,
            'status' => 'draft', 'starts_at' => '', 'ends_at' => '', 'placement' => 'home_hero'];
        $this->itemIds = [];
        $this->itemSearch = '';
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $s = HomepageSection::with('items')->findOrFail($id);
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->editingId = $s->id;
        $this->form = ['type' => $s->type, 'title_en' => (string) $s->title_en, 'title_ar' => (string) $s->title_ar, 'subtitle_en' => (string) $s->subtitle_en, 'subtitle_ar' => (string) $s->subtitle_ar,
            'layout' => $s->layout, 'item_limit' => $s->item_limit, 'status' => $s->status, 'starts_at' => $local($s->starts_at), 'ends_at' => $local($s->ends_at),
            'placement' => $s->settings['placement'] ?? 'home_hero'];
        $this->itemIds = $s->items->sortBy('sort_order')->pluck('item_id')->all();
        $this->itemSearch = '';
        $this->resetValidation();
        $this->showEditor = true;
    }

    public static function itemType(string $sectionType): ?string
    {
        return match ($sectionType) {
            'featured_products', 'product_collection' => 'product', 'categories' => 'category', 'brands' => 'brand', default => null,
        };
    }

    public function addItem(string $id): void
    {
        if (! in_array($id, $this->itemIds, true) && count($this->itemIds) < 50) {
            $this->itemIds[] = $id;
        }
        $this->itemSearch = '';
    }

    public function removeItem(int $i): void
    {
        unset($this->itemIds[$i]);
        $this->itemIds = array_values($this->itemIds);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->can($this->editingId ? 'cms.edit' : 'cms.create'), 403);
        $data = $this->validate([
            'form.type' => 'required|in:'.implode(',', self::TYPES), 'form.layout' => 'required|in:'.implode(',', self::LAYOUTS),
            'form.title_en' => 'nullable|string|max:255', 'form.title_ar' => 'nullable|string|max:255', 'form.subtitle_en' => 'nullable|string|max:255', 'form.subtitle_ar' => 'nullable|string|max:255',
            'form.item_limit' => 'required|integer|min:1|max:50', 'form.status' => 'required|in:draft,published',
            'form.starts_at' => 'nullable|date', 'form.ends_at' => 'nullable|date|after:form.starts_at',
            'form.placement' => 'nullable|in:home_hero,home_strip,category_top,auctions',
            'itemIds' => 'array|max:50', 'itemIds.*' => 'uuid',
        ], [], ['form.ends_at' => __('content_admin.ends_at')])['form'];
        if ($data['status'] === 'published' && trim((string) $data['title_en']) === '' && $data['type'] !== 'banners') {
            $this->addError('form.title_en', __('content_admin.title_required_to_publish'));

            return;
        }
        $instant = fn ($v) => $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
        $itemType = self::itemType($data['type']);
        DB::transaction(function () use ($data, $instant, $itemType, $audit) {
            $s = $this->editingId ? HomepageSection::findOrFail($this->editingId) : new HomepageSection(['sort_order' => (int) HomepageSection::max('sort_order') + 1]);
            $old = $s->exists ? $s->only(['type', 'status', 'title_en', 'starts_at', 'ends_at']) : [];
            $s->fill(collect($data)->except(['placement', 'starts_at', 'ends_at'])->map(fn ($v) => $v === '' ? null : $v)->all() + [
                'starts_at' => $instant($data['starts_at']), 'ends_at' => $instant($data['ends_at']),
                'settings' => $data['type'] === 'banners' ? ['placement' => $data['placement'] ?: 'home_hero'] : null,
            ])->save();
            $s->items()->delete();
            if ($itemType) {
                foreach (array_values($this->itemIds) as $i => $id) {
                    $s->items()->create(['item_type' => $itemType, 'item_id' => $id, 'sort_order' => $i]);
                }
            }
            $audit->log($old ? 'cms.section_updated' : 'cms.section_created', $s, $old, $s->only(['type', 'status', 'title_en', 'starts_at', 'ends_at']), ['items' => count($this->itemIds)]);
        });
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    /** wire:sort handler and keyboard move: place section $id at zero-based $position, renumbering the rest. */
    public function reorder(string $id, int $position, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $ids = HomepageSection::orderBy('sort_order')->orderBy('created_at')->pluck('id')->all();
        abort_unless(in_array($id, $ids, true), 404);
        $ids = array_values(array_diff($ids, [$id]));
        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $sid) {
                HomepageSection::whereKey($sid)->update(['sort_order' => $i + 1]);
            }
        });
        $audit->log('cms.sections_reordered', 'HomepageSection', [], ['order' => $ids]);
    }

    public function move(string $id, int $delta, AuditLogger $audit): void
    {
        $ids = HomepageSection::orderBy('sort_order')->orderBy('created_at')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        abort_if($pos === false, 404);
        $this->reorder($id, max(0, $pos + ($delta < 0 ? -1 : 1)), $audit);
    }

    public function toggle(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $s = HomepageSection::findOrFail($id);
        $to = $s->status === 'published' ? 'draft' : 'published';
        $s->update(['status' => $to]);
        $audit->log('cms.section_'.($to === 'published' ? 'published' : 'hidden'), $s, ['status' => $s->getOriginal('status')], ['status' => $to]);
        $this->toast($to === 'published' ? __('content_admin.section_shown') : __('content_admin.section_hidden'));
    }

    public function delete(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $s = HomepageSection::findOrFail($id);
        $snapshot = $s->only(['type', 'title_en', 'status']);
        $s->delete();
        $audit->log('cms.section_deleted', 'HomepageSection', $snapshot + ['id' => $id]);
        $this->toast(__('content_admin.section_deleted'));
    }

    public function render(HomepageService $home)
    {
        $itemType = $this->showEditor ? self::itemType($this->form['type'] ?? '') : null;
        $term = trim($this->itemSearch);
        $lookup = fn (array $ids) => match ($itemType) {
            'product' => Product::with('translations')->whereIn('id', $ids)->get()->mapWithKeys(fn ($p) => [$p->id => $p->trans('name')]),
            'category' => Category::with('translations')->whereIn('id', $ids)->get()->mapWithKeys(fn ($c) => [$c->id => $c->trans('name')]),
            'brand' => Brand::whereIn('id', $ids)->get()->mapWithKeys(fn ($b) => [$b->id => $b->localized('name')]),
            default => collect(),
        };
        $results = collect();
        if ($itemType && mb_strlen($term) >= 2) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $results = match ($itemType) {
                'product' => Product::with('translations')->where(fn ($q) => $q->where('sku', 'ilike', $like)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $like)))->limit(8)->get()->mapWithKeys(fn ($p) => [$p->id => $p->trans('name')]),
                'category' => Category::with('translations')->whereHas('translations', fn ($t) => $t->where('name', 'ilike', $like))->limit(8)->get()->mapWithKeys(fn ($c) => [$c->id => $c->trans('name')]),
                'brand' => Brand::where(fn ($q) => $q->where('name_en', 'ilike', $like)->orWhere('name_ar', 'ilike', $like))->limit(8)->get()->mapWithKeys(fn ($b) => [$b->id => $b->localized('name')]),
                default => collect(),
            };
        }
        $locale = app()->getLocale();
        app()->setLocale($this->previewLocale);
        try {
            $preview = $home->render(null)['sections'];
        } finally {
            app()->setLocale($locale);
        }

        return view('livewire.admin.content.homepage', [
            'sections' => HomepageSection::withCount('items')->orderBy('sort_order')->orderBy('created_at')->get(),
            'itemType' => $itemType,
            'itemNames' => $itemType ? $lookup($this->itemIds) : collect(),
            'results' => $results,
            'preview' => $preview,
            'canEdit' => $this->can('cms.edit'),
            'canCreate' => $this->can('cms.create'),
            'canDelete' => $this->can('cms.delete'),
        ])->title(__('nav.homepage'));
    }
}
