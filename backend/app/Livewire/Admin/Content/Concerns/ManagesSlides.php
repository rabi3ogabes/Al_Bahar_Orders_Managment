<?php

namespace App\Livewire\Admin\Content\Concerns;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\BannerLinks;
use App\Domain\Cms\Services\HomeLayout;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;

/**
 * The slides: an image per language, optional text, an optional link, a schedule and a place — one of the sliders, or one of the
 * "other places" (category top, auctions). Add, edit, move up or down, hide, duplicate, delete with Undo.
 */
trait ManagesSlides
{
    use StoresCatalogMedia, WithFileUploads;

    public bool $showSlide = false;

    #[Locked]
    public ?string $slideId = null;

    /** @var array<string, mixed> */
    public array $slide = [];

    public $imageEn = null;

    public $imageAr = null;

    /** Product search text of the link picker. */
    public string $linkSearch = '';

    /** Bumped every time the slide window opens, so its live preview starts from the slide being edited, not from the last one. */
    public int $editorKey = 0;

    /** "Remove the Arabic image": the slide goes back to showing the English artwork in Arabic. */
    public bool $dropImageAr = false;

    /** A slide that is still there, or null after telling the admin it is not (another admin deleted it, a double click, an old page). */
    private function slideOrGone(string $id): ?Banner
    {
        $b = Banner::find($id);
        if (! $b) {
            $this->toast(__('banners_admin.gone'), 'error');
        }

        return $b;
    }

    public function createSlide(string $destination): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->slideId = null;
        $this->slide = $this->blankSlide($destination);
        $this->openSlideEditor();
    }

    public function editSlide(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        if (! ($b = $this->slideOrGone($id))) {
            return;
        }
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->slideId = $b->id;
        $this->slide = ['destination' => $this->destinationOf($b), 'title_en' => (string) $b->title_en, 'title_ar' => (string) $b->title_ar, 'subtitle_en' => (string) $b->subtitle_en,
            'subtitle_ar' => (string) $b->subtitle_ar, 'platform' => $b->platform, 'link_type' => (string) $b->link_type, 'link_value' => (string) $b->link_value,
            'status' => $b->status, 'starts_at' => $local($b->starts_at), 'ends_at' => $local($b->ends_at)];
        $this->openSlideEditor();
    }

    private function openSlideEditor(): void
    {
        $this->reset('imageEn', 'imageAr', 'linkSearch', 'dropImageAr');
        $this->resetValidation();
        $this->editorKey++;
        $this->showSlide = true;
    }

    /** @return array<string, mixed> */
    private function blankSlide(string $destination): array
    {
        return ['destination' => $destination, 'title_en' => '', 'title_ar' => '', 'subtitle_en' => '', 'subtitle_ar' => '', 'platform' => 'all',
            'link_type' => '', 'link_value' => '', 'status' => 'published', 'starts_at' => '', 'ends_at' => ''];
    }

    /** `slider:<id>` for a slide of a slider, `place:<placement>` for the other places, '' for an old slide that no slider shows. */
    private function destinationOf(Banner $b): string
    {
        if ($b->homepage_section_id) {
            return 'slider:'.$b->homepage_section_id;
        }

        return in_array($b->placement, Banner::OTHER_PLACEMENTS, true) ? 'place:'.$b->placement : '';
    }

    /** @return array{0: ?string, 1: string} [slider id or null, placement] — or a validation error for a destination that does not exist. */
    private function parseDestination(string $destination): array
    {
        if (str_starts_with($destination, 'slider:') && Str::isUuid(substr($destination, 7))) {
            $s = HomepageSection::where('type', 'banners')->find(substr($destination, 7));
            if ($s) {
                return [$s->id, 'home_hero'];
            }
        }
        if (str_starts_with($destination, 'place:') && in_array(substr($destination, 6), Banner::OTHER_PLACEMENTS, true)) {
            return [null, substr($destination, 6)];
        }
        throw ValidationException::withMessages(['slide.destination' => __('banners_admin.destination_invalid')]);
    }

    /** Changing the kind of link starts the value afresh, so a category id can never be saved as a web address. */
    public function updatedSlideLinkType(): void
    {
        $this->slide['link_value'] = '';
        $this->linkSearch = '';
        $this->resetErrorBag('slide.link_value');
    }

    public function pickLink(string $value): void
    {
        abort_unless($this->can($this->slideId ? 'cms.edit' : 'cms.create'), 403);
        $this->slide['link_value'] = $value;
        $this->linkSearch = '';
        $this->resetErrorBag('slide.link_value');
    }

    public function clearLink(): void
    {
        abort_unless($this->can($this->slideId ? 'cms.edit' : 'cms.create'), 403);
        $this->slide['link_type'] = '';
        $this->slide['link_value'] = '';
        $this->resetErrorBag('slide.link_value');
    }

    public function saveSlide(AuditLogger $audit, HomeLayout $layout, BannerLinks $links): void
    {
        abort_unless($this->can($this->slideId ? 'cms.edit' : 'cms.create'), 403);
        $b = $this->slideId ? $this->slideOrGone($this->slideId) : new Banner;
        if (! $b) {
            $this->showSlide = false;

            return;
        }
        $type = (string) ($this->slide['link_type'] ?? '');
        $data = $this->validate([
            'slide.destination' => 'required|string|max:80',
            'slide.title_en' => 'nullable|string|max:120', 'slide.title_ar' => 'nullable|string|max:120', 'slide.subtitle_en' => 'nullable|string|max:200', 'slide.subtitle_ar' => 'nullable|string|max:200',
            'slide.platform' => 'required|in:all,mobile,web', 'slide.status' => 'required|in:draft,published',
            'slide.starts_at' => 'nullable|date', 'slide.ends_at' => 'nullable|date|after:slide.starts_at',
            'slide.link_type' => 'nullable|in:'.implode(',', Banner::LINK_TYPES),
            'slide.link_value' => $type === '' ? 'nullable' : ['required', 'string', 'max:'.BannerLinks::MAX_URL, function (string $attribute, mixed $value, \Closure $fail) use ($type) {
                if (BannerLinks::normalize($type, (string) $value) === null) {
                    $fail(__('banners_admin.link_invalid_'.$type));
                }
            }],
            'imageEn' => array_merge($b->image_en_path ? ['nullable'] : ['required'], $this->slideImageRules()), 'imageAr' => $this->slideImageRules(),
        ], [], ['imageEn' => __('content_admin.image_en'), 'imageAr' => __('content_admin.image_ar'), 'slide.link_value' => __('content_admin.link_value'),
            'slide.ends_at' => __('content_admin.ends_at'), 'slide.destination' => __('banners_admin.shows_in')]);
        $data = $data['slide'];
        [$sectionId, $placement] = $this->parseDestination((string) $data['destination']);

        $link = BannerLinks::normalize($type, (string) ($data['link_value'] ?? ''));
        $changed = $link !== null && ($link['type'] !== $b->link_type || $link['value'] !== $b->link_value);
        if ($changed && ! ($links->describe($link['type'], $link['value'])['ok'] ?? false)) {
            $this->addError('slide.link_value', __('banners_admin.link_missing_'.$link['type']));

            return;
        }

        $instant = fn ($v) => $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
        $blank = fn ($v) => trim((string) $v) === '' ? null : trim((string) $v);
        $fields = ['title_en' => $blank($data['title_en']), 'title_ar' => $blank($data['title_ar']), 'subtitle_en' => $blank($data['subtitle_en']), 'subtitle_ar' => $blank($data['subtitle_ar']),
            'platform' => $data['platform'], 'status' => $data['status'], 'starts_at' => $instant($data['starts_at']), 'ends_at' => $instant($data['ends_at']),
            'link_type' => $link['type'] ?? null, 'link_value' => $link['value'] ?? null];
        $snapshot = fn (Banner $x) => $x->only(['homepage_section_id', 'placement', 'status', 'title_en', 'link_type', 'link_value']);
        $old = $b->exists ? $snapshot($b) : [];

        $newFiles = [];
        $oldFiles = [];
        try {
            foreach (['imageEn' => 'image_en_path', 'imageAr' => 'image_ar_path'] as $upload => $column) {
                if ($this->{$upload}) {
                    $oldFiles[] = $b->{$column};
                    $fields[$column] = $newFiles[] = $this->storeMedia($this->{$upload}, 'banners');
                }
            }
            if ($b->exists) {
                // Moving to another slider first: when that one is full nothing has been saved yet and the new pictures are cleaned up below.
                if ($this->destinationOf($b) !== $data['destination']) {
                    $layout->relocateSlide($b, $sectionId, $placement);
                    $b->refresh();
                }
                $b->fill($fields)->save();
            } else {
                $b = $layout->addSlide($fields, $sectionId, $placement);
            }
        } catch (\Throwable $e) {
            array_map(fn (string $path) => $this->deleteMedia($path), $newFiles);
            if ($e instanceof DomainException) {
                $this->toast($e->getMessage(), 'error');

                return;
            }
            throw $e;
        }
        array_map(fn (?string $path) => $this->deleteMedia($path), $oldFiles);
        if ($this->dropImageAr && ! $this->imageAr && $b->image_ar_path) {
            $this->deleteMedia($b->image_ar_path);
            $b->forceFill(['image_ar_path' => null])->save();
        }

        $audit->log($old ? 'cms.banner_updated' : 'cms.banner_created', $b, $old, $snapshot($b));
        $this->showSlide = false;
        $this->slideId = null;
        $this->reset('imageEn', 'imageAr', 'linkSearch', 'dropImageAr');
        $this->toast($b->isLive() ? __('banners_admin.slide_saved_live') : __('banners_admin.slide_saved_hidden'));
    }

    /** @return list<mixed> */
    private function slideImageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(int) config('platform.media.max_image_kb', 8192), 'dimensions:min_width=600,min_height=200'];
    }

    public function toggleSlide(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        if (! ($b = $this->slideOrGone($id))) {
            return;
        }
        $to = $b->status === 'published' ? 'draft' : 'published';
        $b->update(['status' => $to]);
        $audit->log('cms.banner_'.($to === 'published' ? 'published' : 'hidden'), $b, [], ['status' => $to]);
        $this->toast($to === 'published' ? __('banners_admin.slide_shown') : __('banners_admin.slide_hidden'));
    }

    public function moveSlide(string $id, int $delta, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->attempt(fn () => $this->applySlideMove($id, fn () => $layout->moveSlide($id, $delta), $audit, $delta < 0 ? __('banners_admin.slide_moved_up') : __('banners_admin.slide_moved_down')));
    }

    /** wire:sort handler: a slide dragged to a zero-based position inside its slider. */
    public function reorderSlide(string $id, int $position, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->attempt(fn () => $this->applySlideMove($id, fn () => $layout->placeSlide($id, $position), $audit, __('banners_admin.slide_moved')));
    }

    /** @param  callable(): array{before: list<string>, after: list<string>}  $move */
    private function applySlideMove(string $id, callable $move, AuditLogger $audit, string $message): void
    {
        if (! ($slide = $this->slideOrGone($id))) {
            return;
        }
        $moved = $move();
        if ($moved['before'] === $moved['after']) {
            return;
        }
        $audit->log('cms.banner_moved', $slide, [], ['order' => $moved['after']]);
        $this->dispatch('toast', type: 'success', message: $message, undoLabel: __('admin.undo'), undoEvent: 'slide-order-undo',
            undoParams: ['order' => $moved['before'], 'sectionId' => $slide->homepage_section_id, 'placement' => $slide->placement]);
    }

    #[On('slide-order-undo')]
    public function undoSlideMove(array $order, ?string $sectionId, string $placement, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $layout->restoreSlideOrder($sectionId, $placement, array_values(array_filter($order, 'is_string')));
        $audit->log('cms.banner_moved', 'Banner', [], ['order' => $order, 'undo' => true]);
    }

    public function duplicateSlide(string $id, AuditLogger $audit, HomeLayout $layout): void
    {
        abort_unless($this->can('cms.create'), 403);
        if (! ($src = $this->slideOrGone($id))) {
            return;
        }
        $copied = [];
        try {
            $copy = $layout->addSlide([
                'title_en' => $src->title_en, 'title_ar' => $src->title_ar, 'subtitle_en' => $src->subtitle_en, 'subtitle_ar' => $src->subtitle_ar, 'platform' => $src->platform,
                'link_type' => $src->link_type, 'link_value' => $src->link_value, 'starts_at' => $src->starts_at, 'ends_at' => $src->ends_at,
                'status' => 'draft', // a copy never goes live by surprise
                'image_en_path' => $copied[] = $this->copyMedia($src->image_en_path), 'image_ar_path' => $src->image_ar_path ? ($copied[] = $this->copyMedia($src->image_ar_path)) : null,
            ], $src->homepage_section_id, $src->placement);
        } catch (\Throwable $e) {
            array_map(fn (?string $path) => $this->deleteMedia($path), $copied);
            if ($e instanceof DomainException) {
                $this->toast($e->getMessage(), 'error');

                return;
            }
            throw $e;
        }
        $layout->placeAfter($copy, $src);
        $audit->log('cms.banner_duplicated', $copy, [], ['from' => $src->id]);
        $this->toast(__('banners_admin.slide_duplicated'));
    }

    /** A real copy of the file, so deleting one slide's artwork can never remove another's. */
    private function copyMedia(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http')) {
            return $path;
        }
        $disk = Storage::disk(self::mediaDisk());
        $to = 'banners/'.Str::uuid().'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        $disk->copy($path, $to);
        $disk->setVisibility($to, 'public');

        return $to;
    }

    public function deleteSlide(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.delete'), 403);
        if (! ($b = $this->slideOrGone($id))) {
            return;
        }
        $b->delete(); // soft: restorable until the nightly purge
        $audit->log('cms.banner_deleted', 'Banner', ['title_en' => $b->title_en, 'placement' => $b->placement, 'slider' => $b->homepage_section_id], ['id' => $id]);
        $this->dispatch('toast', type: 'success', message: __('banners_admin.slide_deleted'), undoLabel: __('admin.undo'), undoEvent: 'slide-restore', undoParams: ['id' => $id]);
    }

    #[On('slide-restore')]
    public function restoreSlide(string $id, AuditLogger $audit, HomeLayout $layout): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $b = Banner::onlyTrashed()->find($id);
        if (! $b) {
            return;
        }
        $b->restore();
        $layout->restoreSlideOrder($b->homepage_section_id, $b->placement, $layout->slides($b->homepage_section_id, $b->placement)->pluck('id')->all());
        $audit->log('cms.banner_restored', $b, [], ['placement' => $b->placement]);
        $this->toast(__('banners_admin.slide_restored'));
    }
}
