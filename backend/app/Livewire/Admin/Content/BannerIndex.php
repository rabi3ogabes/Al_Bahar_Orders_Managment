<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Models\Banner;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Banners per placement/platform with EN/AR artwork, link target, ordering and schedule. */
class BannerIndex extends Component
{
    use StoresCatalogMedia, WithAdminTable, WithFileUploads;

    public const PLACEMENTS = ['home_hero', 'home_strip', 'category_top', 'auctions'];

    public const LINKS = ['product', 'category', 'brand', 'auction', 'page', 'url'];

    #[Url(except: '')]
    public string $filterPlacement = '';

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    public array $form = [];

    public $imageEn = null;

    public $imageAr = null;

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->editingId = null;
        $this->form = ['placement' => $this->filterPlacement ?: 'home_hero', 'platform' => 'all', 'title_en' => '', 'title_ar' => '', 'subtitle_en' => '', 'subtitle_ar' => '',
            'link_type' => '', 'link_value' => '', 'sort_order' => (int) Banner::max('sort_order') + 1, 'status' => 'draft', 'starts_at' => '', 'ends_at' => ''];
        $this->reset('imageEn', 'imageAr');
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $b = Banner::findOrFail($id);
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->editingId = $b->id;
        $this->form = collect($b->only(['placement', 'platform', 'title_en', 'title_ar', 'subtitle_en', 'subtitle_ar', 'link_type', 'link_value', 'sort_order', 'status']))
            ->map(fn ($v) => $v ?? '')->all() + ['starts_at' => $local($b->starts_at), 'ends_at' => $local($b->ends_at)];
        $this->reset('imageEn', 'imageAr');
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->can($this->editingId ? 'cms.edit' : 'cms.create'), 403);
        $b = $this->editingId ? Banner::findOrFail($this->editingId) : new Banner;
        $data = $this->validate([
            'form.placement' => 'required|in:'.implode(',', self::PLACEMENTS), 'form.platform' => 'required|in:all,mobile,web',
            'form.title_en' => 'nullable|string|max:255', 'form.title_ar' => 'nullable|string|max:255', 'form.subtitle_en' => 'nullable|string|max:255', 'form.subtitle_ar' => 'nullable|string|max:255',
            'form.link_type' => 'nullable|in:'.implode(',', self::LINKS), 'form.link_value' => array_filter(['required_with:form.link_type', 'nullable', 'string', 'max:255', ($this->form['link_type'] ?? '') === 'url' ? 'url:https' : null]),
            'form.sort_order' => 'required|integer|min:0|max:100000', 'form.status' => 'required|in:draft,published',
            'form.starts_at' => 'nullable|date', 'form.ends_at' => 'nullable|date|after:form.starts_at',
            'imageEn' => array_merge($b->image_en_path ? [] : ['required'], self::imageRule(6144)), 'imageAr' => self::imageRule(6144),
        ], [], ['imageEn' => __('content_admin.image_en'), 'imageAr' => __('content_admin.image_ar'), 'form.link_value' => __('content_admin.link_value'), 'form.ends_at' => __('content_admin.ends_at')])['form'];
        $instant = fn ($v) => $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
        $old = $b->exists ? $b->only(['placement', 'status', 'title_en', 'link_type', 'link_value']) : [];
        $b->fill(collect($data)->except(['starts_at', 'ends_at'])->map(fn ($v) => $v === '' ? null : $v)->all() + ['starts_at' => $instant($data['starts_at']), 'ends_at' => $instant($data['ends_at'])]);
        if ($this->imageEn) {
            $this->deleteMedia($b->image_en_path);
            $b->image_en_path = $this->storeMedia($this->imageEn, 'banners');
        }
        if ($this->imageAr) {
            $this->deleteMedia($b->image_ar_path);
            $b->image_ar_path = $this->storeMedia($this->imageAr, 'banners');
        }
        $b->save();
        $audit->log($old ? 'cms.banner_updated' : 'cms.banner_created', $b, $old, $b->only(['placement', 'status', 'title_en', 'link_type', 'link_value']));
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function toggle(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $b = Banner::findOrFail($id);
        $to = $b->status === 'published' ? 'draft' : 'published';
        $b->update(['status' => $to]);
        $audit->log('cms.banner_'.($to === 'published' ? 'published' : 'hidden'), $b, [], ['status' => $to]);
    }

    public function delete(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $b = Banner::findOrFail($id);
        $snap = $b->only(['placement', 'title_en', 'image_en_path']);
        $this->deleteMedia($b->image_en_path);
        $this->deleteMedia($b->image_ar_path);
        $b->delete();
        $audit->log('cms.banner_deleted', 'Banner', $snap + ['id' => $id]);
        $this->toast(__('content_admin.banner_deleted'));
    }

    public function render()
    {
        $s = trim($this->search);

        return view('livewire.admin.content.banners', [
            'banners' => Banner::query()->when($this->filterPlacement, fn ($q, $p) => $q->where('placement', $p))
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('title_en', 'ilike', "%{$s}%")->orWhere('title_ar', 'ilike', "%{$s}%")))
                ->orderBy('placement')->orderBy('sort_order')->paginate(24),
            'current' => $this->editingId ? Banner::find($this->editingId) : null,
            'canEdit' => $this->can('cms.edit'), 'canCreate' => $this->can('cms.create'), 'canDelete' => $this->can('cms.delete'),
        ])->title(__('nav.banners'));
    }
}
