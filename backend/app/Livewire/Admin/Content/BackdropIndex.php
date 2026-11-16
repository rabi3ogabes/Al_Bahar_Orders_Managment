<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Services\BackdropService;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Contrast;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Appearance > Page background: the calm picture behind every page of the web store and the customer app (the Doha
 * skyline, the admin's own picture or nothing), its strength and the page colour. Deferred save: nothing reaches the store
 * until Save. The picture path never travels through the browser, only the five look fields do.
 */
class BackdropIndex extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    /** What the form edits: style, fit, opacity (whole percent), color and tint (hex or null). @var array<string, mixed> */
    public array $form = [];

    /** The last saved values of those fields, to know when there is something to save. @var array<string, mixed> */
    public array $baseline = [];

    /** A picture chosen but not saved yet. */
    public $upload = null;

    public bool $justSaved = false;

    public function mount(BackdropService $backdrop): void
    {
        abort_unless($this->can('cms.view'), 403);
        $this->baseline = Arr::only($backdrop->config(), BackdropService::FIELDS);
        $this->form = $this->baseline;
    }

    /** Every change a browser sends is an edit: only people who may edit can send one. */
    public function updating(string $name, mixed $value): void
    {
        abort_unless($this->can('cms.edit'), 403);
    }

    public function updated(): void
    {
        $this->justSaved = false;
    }

    /** A picture was chosen: check it at once, and pick "My picture" so the preview shows it. */
    public function updatedUpload(): void
    {
        try {
            $this->validate(['upload' => BackdropService::imageRules()], [], ['upload' => __('backdrop_admin.attr_picture')]);
        } catch (ValidationException $e) {
            $this->reset('upload');
            throw $e;
        }
        $this->form['style'] = 'image';
    }

    /** Drops a picture that was chosen but not saved (nothing was stored yet, so no confirmation). */
    public function discardUpload(BackdropService $backdrop): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->reset('upload');
        $this->resetValidation('upload');
        if (($this->form['style'] ?? null) === 'image' && $backdrop->imageUrl($backdrop->config()['image']) === null) {
            $this->form['style'] = $this->baseline['style'] === 'image' ? 'skyline' : $this->baseline['style'];
        }
    }

    public function save(BackdropService $backdrop, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->resetValidation();
        $old = $backdrop->config();
        try {
            $clean = $backdrop->save($this->form, $this->upload);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key === 'image' ? 'upload' : 'form.'.$key, $message);
                }
            }

            return;
        }
        $audit->log('cms.backdrop_updated', 'Backdrop', $old, $clean);
        $this->baseline = $this->form = Arr::only($clean, BackdropService::FIELDS);
        $this->reset('upload');
        $this->justSaved = true;
        $this->dispatch('backdrop-saved');
        $this->toast(__('admin.saved'));
    }

    /** Deletes the stored picture for good (the confirmation is asked in the page). */
    public function removePicture(BackdropService $backdrop, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $old = $backdrop->config();
        if ($old['image'] === null) {
            return;
        }
        $clean = $backdrop->removeImage();
        $audit->log('cms.backdrop_image_removed', 'Backdrop', ['image' => $old['image'], 'style' => $old['style']], ['image' => null, 'style' => $clean['style']]);
        // The saved style may have changed (the picture was the live background); the admin's other unsaved choices stay.
        $this->baseline = Arr::only($clean, BackdropService::FIELDS);
        if (($this->form['style'] ?? null) === 'image' && $this->upload === null) {
            $this->form['style'] = 'skyline';
        }
        $this->resetValidation();
        $this->toast(__('backdrop_admin.removed'));
    }

    public function resetToDefault(BackdropService $backdrop, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->resetValidation();
        $old = $backdrop->config();
        $clean = $backdrop->reset();
        $audit->log('cms.backdrop_reset', 'Backdrop', $old, $clean);
        $this->baseline = $this->form = Arr::only($clean, BackdropService::FIELDS);
        $this->reset('upload');
        $this->justSaved = false;
        $this->toast(__('backdrop_admin.reset_done'));
    }

    /** Whether there is something to save: a new picture, or fields that differ from what is saved. */
    private function isDirty(BackdropService $backdrop): bool
    {
        if ($this->upload !== null) {
            return true;
        }
        try {
            $current = Arr::only($backdrop->validate($this->form), BackdropService::FIELDS);
        } catch (ValidationException) {
            return true; // not valid yet: let the admin press Save to see what is wrong
        }

        return $current !== $this->baseline;
    }

    public function render(BackdropService $backdrop, ThemeService $themes)
    {
        $stored = $backdrop->imageUrl($backdrop->config()['image']);
        $pending = $this->upload && method_exists($this->upload, 'isPreviewable') && $this->upload->isPreviewable() ? $this->upload->temporaryUrl() : null;

        $colors = $themes->active()?->getAttribute('colors');
        $colors = is_array($colors) ? $colors : [];
        $theme = [
            'background' => Contrast::isHex($colors['background'] ?? null) ? $colors['background'] : '#FCF9FA',
            'surface' => Contrast::isHex($colors['surface'] ?? null) ? $colors['surface'] : '#FFFFFF',
            'primary' => Contrast::isHex($colors['primary'] ?? null) ? $colors['primary'] : '#8A1538',
        ];
        // Dark mode lifts the primary until it reads on a dark page; the apps do the same with their own maths.
        $theme['primary_dark'] = Contrast::mix($theme['primary'], '#FFFFFF', 0.4);

        $color = $this->form['color'] ?? null;
        $ratio = Contrast::isHex($color) ? Contrast::ratio($color, $backdrop->ink()) : null;
        $needsPicture = ($this->form['style'] ?? null) === 'image' && $stored === null && $this->upload === null;

        return view('livewire.admin.content.backdrop', [
            'dirty' => $this->isDirty($backdrop),
            'blocked' => $needsPicture || ($ratio !== null && $ratio < Contrast::AA),
            'needsPicture' => $needsPicture,
            'ratio' => $ratio,
            'minRatio' => Contrast::AA,
            'storedUrl' => $stored,
            'previewImage' => $pending ?? $stored,
            'theme' => $theme,
            'canEdit' => $this->can('cms.edit'),
            'styles' => BackdropService::STYLES, 'fits' => BackdropService::FITS,
            'min' => BackdropService::OPACITY_MIN, 'max' => BackdropService::OPACITY_MAX,
        ])->title(__('backdrop_admin.title'));
    }
}
