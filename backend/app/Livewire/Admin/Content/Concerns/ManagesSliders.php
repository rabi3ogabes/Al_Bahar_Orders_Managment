<?php

namespace App\Livewire\Admin\Content\Concerns;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomeLayout;
use App\Domain\Cms\Support\SliderSettings;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The sliders of the home page (a `banners` home section): add one where you want it, change how it behaves, move it up or down among the
 * other home sections, publish or hide it, delete it (with Undo). Every change goes through HomeLayout, which orders under a lock.
 */
trait ManagesSliders
{
    public bool $showSlider = false;

    #[Locked]
    public ?string $sliderId = null;

    /** @var array<string, mixed> */
    public array $slider = [];

    public bool $showDeleteSlider = false;

    #[Locked]
    public ?string $deleteSliderId = null;

    public function createSlider(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->sliderId = null;
        $first = ! HomepageSection::where('type', 'banners')->exists();
        $this->slider = SliderSettings::DEFAULTS + ['name' => '', 'title_en' => '', 'title_ar' => '', 'status' => 'published', 'starts_at' => '', 'ends_at' => '', 'at' => $first ? 'top' : 'bottom'];
        $this->resetValidation();
        $this->showSlider = true;
    }

    /** A slider that is still there, or null after telling the admin it is not. */
    private function sliderOrGone(string $id): ?HomepageSection
    {
        $s = HomepageSection::where('type', 'banners')->find($id);
        if (! $s) {
            $this->toast(__('banners_admin.gone'), 'error');
        }

        return $s;
    }

    public function editSlider(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        if (! ($s = $this->sliderOrGone($id))) {
            return;
        }
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->sliderId = $s->id;
        $this->slider = $s->slider() + ['name' => $s->adminName(), 'title_en' => (string) $s->title_en, 'title_ar' => (string) $s->title_ar, 'status' => $s->status,
            'starts_at' => $local($s->starts_at), 'ends_at' => $local($s->ends_at), 'at' => 'bottom'];
        $this->resetValidation();
        $this->showSlider = true;
    }

    public function saveSlider(AuditLogger $audit, HomeLayout $layout): void
    {
        abort_unless($this->can($this->sliderId ? 'cms.edit' : 'cms.create'), 403);
        $data = $this->validate([
            'slider.name' => 'nullable|string|max:80', 'slider.title_en' => 'nullable|string|max:120', 'slider.title_ar' => 'nullable|string|max:120',
            'slider.autoplay' => 'boolean', 'slider.interval' => 'required|integer|min:'.SliderSettings::MIN_INTERVAL.'|max:'.SliderSettings::MAX_INTERVAL,
            'slider.aspect' => 'required|in:'.implode(',', SliderSettings::ASPECTS), 'slider.dots' => 'boolean', 'slider.show_titles' => 'boolean', 'slider.rounded' => 'boolean',
            'slider.status' => 'required|in:draft,published', 'slider.starts_at' => 'nullable|date', 'slider.ends_at' => 'nullable|date|after:slider.starts_at',
            'slider.at' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $ok = $value === 'top' || $value === 'bottom' || (is_string($value) && str_starts_with($value, 'after:') && HomepageSection::whereKey(substr($value, 6))->exists());
                if (! $ok) {
                    $fail(__('banners_admin.position_invalid'));
                }
            }],
        ], [], ['slider.interval' => __('banners_admin.interval'), 'slider.ends_at' => __('content_admin.ends_at'), 'slider.title_en' => __('content_admin.title_en'), 'slider.title_ar' => __('content_admin.title_ar')])['slider'];

        $instant = fn ($v) => $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
        $blank = fn ($v) => trim((string) $v) === '' ? null : trim((string) $v);
        $look = SliderSettings::normalize($data);
        $attributes = ['type' => 'banners', 'layout' => 'carousel', 'title_en' => $blank($data['title_en']), 'title_ar' => $blank($data['title_ar']),
            'status' => $data['status'], 'starts_at' => $instant($data['starts_at']), 'ends_at' => $instant($data['ends_at'])];

        if ($this->sliderId) {
            if (! ($s = $this->sliderOrGone($this->sliderId))) {
                $this->showSlider = false;

                return;
            }
            $old = $s->only(['status', 'title_en', 'starts_at', 'ends_at']) + ['slider' => $s->slider()];
            $s->fill($attributes + ['settings' => array_replace($s->settings ?? [], ['name' => $blank($data['name']), 'slider' => $look])])->save();
            $audit->log('cms.slider_updated', $s, $old, $s->only(['status', 'title_en', 'starts_at', 'ends_at']) + ['slider' => $look]);
        } else {
            $s = $layout->addSection($attributes + ['item_limit' => 12, 'settings' => ['name' => $blank($data['name']), 'slider' => $look]], $data['at']);
            $audit->log('cms.slider_created', $s, [], ['status' => $s->status, 'position' => $data['at'], 'slider' => $look]);
        }
        $this->showSlider = false;
        $this->toast($this->sliderId ? __('banners_admin.slider_saved') : __('banners_admin.slider_created'));
        $this->sliderId = null;
    }

    /** Moves a slider one step among ALL the home sections (not only the other sliders). */
    public function moveSlider(string $id, int $delta, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->attempt(function () use ($id, $delta, $layout, $audit) {
            $moved = $layout->moveSection($id, $delta);
            if ($moved['before'] === $moved['after']) {
                return;
            }
            $audit->log('cms.slider_moved', HomepageSection::find($id), [], ['order' => $moved['after']]);
            $this->dispatch('toast', type: 'success', message: $delta < 0 ? __('banners_admin.slider_moved_up') : __('banners_admin.slider_moved_down'),
                undoLabel: __('admin.undo'), undoEvent: 'slider-order-undo', undoParams: ['order' => $moved['before']]);
        });
    }

    #[On('slider-order-undo')]
    public function undoSliderMove(array $order, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $layout->restoreSectionOrder(array_values(array_filter($order, 'is_string')));
        $audit->log('cms.slider_moved', 'HomepageSection', [], ['order' => $order, 'undo' => true]);
    }

    public function toggleSlider(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        if (! ($s = $this->sliderOrGone($id))) {
            return;
        }
        $to = $s->status === 'published' ? 'draft' : 'published';
        $s->update(['status' => $to]);
        $audit->log('cms.slider_'.($to === 'published' ? 'published' : 'hidden'), $s, [], ['status' => $to]);
        $this->toast($to === 'published' ? __('banners_admin.slider_shown') : __('banners_admin.slider_hidden'));
    }

    public function askDeleteSlider(string $id): void
    {
        abort_unless($this->can('cms.delete'), 403);
        if ($s = $this->sliderOrGone($id)) {
            $this->deleteSliderId = $s->id;
            $this->showDeleteSlider = true;
        }
    }

    public function deleteSlider(AuditLogger $audit, HomeLayout $layout): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $s = HomepageSection::where('type', 'banners')->find($this->deleteSliderId);
        $this->showDeleteSlider = false;
        if (! $s) {
            return;
        }
        $slides = $s->slides()->count();
        $layout->removeSection($s);
        $audit->log('cms.slider_deleted', $s, ['title_en' => $s->title_en, 'name' => $s->adminName()], [], ['slides' => $slides]);
        $this->dispatch('toast', type: 'success', message: __('banners_admin.slider_deleted'), undoLabel: __('admin.undo'), undoEvent: 'slider-restore', undoParams: ['id' => $s->id]);
        $this->deleteSliderId = null;
    }

    #[On('slider-restore')]
    public function restoreSlider(string $id, HomeLayout $layout, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.delete'), 403);
        if ($s = $layout->restoreSection($id)) {
            $audit->log('cms.slider_restored', $s, [], ['status' => $s->status]);
            $this->toast(__('banners_admin.slider_restored'));
        }
    }
}
