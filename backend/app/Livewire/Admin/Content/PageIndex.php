<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Cms\Models\CmsPage;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * CMS pages (about, terms, privacy, …): slug + EN/AR title, rich text and SEO. Saving stores a sanitised draft;
 * publishing promotes the draft to the live body, so edits can be previewed before customers see them.
 */
class PageIndex extends Component
{
    use WithAdminTable;

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    public string $slug = '';

    public array $tr = [];

    public string $previewLocale = '';

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
    }

    private function blankTr(): array
    {
        return collect(['en', 'ar'])->mapWithKeys(fn ($l) => [$l => ['title' => '', 'body' => '', 'seo_title' => '', 'seo_description' => '']])->all();
    }

    public function create(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->editingId = null;
        $this->slug = '';
        $this->tr = $this->blankTr();
        $this->previewLocale = '';
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $p = CmsPage::with('translations')->findOrFail($id);
        $this->editingId = $p->id;
        $this->slug = $p->slug;
        $this->tr = $this->blankTr();
        foreach ($p->translations as $t) {
            $this->tr[$t->locale] = ['title' => $t->title, 'body' => (string) ($t->draft_body ?? $t->body), 'seo_title' => (string) $t->seo_title, 'seo_description' => (string) $t->seo_description];
        }
        $this->previewLocale = '';
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit, ProductService $html, bool $publish = false): void
    {
        abort_unless($this->can($this->editingId ? 'cms.edit' : 'cms.create'), 403);
        if ($publish) {
            abort_unless($this->can('cms.approve') || $this->can('cms.edit'), 403);
        }
        $this->slug = strtolower(trim($this->slug));
        $this->validate([
            'slug' => ['required', 'string', 'max:96', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('cms_pages', 'slug')->ignore($this->editingId)],
            'tr.en.title' => 'required|string|max:255', 'tr.ar.title' => 'required|string|max:255',
            'tr.*.body' => 'nullable|string|max:200000', 'tr.*.seo_title' => 'nullable|string|max:255', 'tr.*.seo_description' => 'nullable|string|max:500',
        ], ['slug.regex' => __('content_admin.slug_format')], ['slug' => __('content_admin.slug'), 'tr.en.title' => __('content_admin.title_en'), 'tr.ar.title' => __('content_admin.title_ar')]);
        $page = DB::transaction(function () use ($html, $publish) {
            $p = $this->editingId ? CmsPage::findOrFail($this->editingId) : new CmsPage(['status' => 'draft']);
            $p->fill(['slug' => $this->slug, 'updated_by' => auth()->id()] + ($publish ? ['status' => 'published', 'published_at' => now()] : []))->save();
            foreach (['en', 'ar'] as $l) {
                $t = $this->tr[$l];
                $clean = (string) $html->sanitizeHtml($t['body']);
                $row = $p->translations()->firstOrNew(['locale' => $l]);
                $row->fill(['title' => $t['title'], 'seo_title' => $t['seo_title'] ?: null, 'seo_description' => $t['seo_description'] ?: null, 'draft_body' => $clean]);
                if ($publish || ! $row->exists) {
                    $row->body = $publish ? $clean : ($row->body ?? '');
                }
                $row->save();
            }

            return $p;
        });
        $audit->log($publish ? 'cms.page_published' : 'cms.page_saved', $page, [], ['slug' => $page->slug, 'status' => $page->status]);
        $this->editingId = $page->id;
        $this->toast($publish ? __('content_admin.page_published') : __('content_admin.draft_saved'));
        if ($publish) {
            $this->showEditor = false;
        }
    }

    public function publish(AuditLogger $audit, ProductService $html): void
    {
        $this->save($audit, $html, true);
    }

    public function unpublish(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $p = CmsPage::findOrFail($id);
        $p->update(['status' => 'draft']);
        $audit->log('cms.page_unpublished', $p, ['status' => 'published'], ['status' => 'draft']);
        $this->toast(__('content_admin.page_unpublished'));
    }

    public function render(ProductService $html)
    {
        $s = trim($this->search);

        return view('livewire.admin.content.pages', [
            'pages' => CmsPage::with('translations')->when($s, fn ($q) => $q->where(fn ($w) => $w->where('slug', 'ilike', "%{$s}%")->orWhereHas('translations', fn ($t) => $t->where('title', 'ilike', "%{$s}%"))))
                ->orderBy('slug')->paginate($this->perPage),
            'previewHtml' => $this->showEditor && $this->previewLocale ? (string) $html->sanitizeHtml($this->tr[$this->previewLocale]['body'] ?? '') : null,
            'canEdit' => $this->can('cms.edit'), 'canCreate' => $this->can('cms.create'),
        ])->title(__('nav.pages'));
    }
}
