<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Services\AppFooterService;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Appearance > App footer: the style and destinations of the customer app's bottom menu. */
class AppFooterIndex extends Component
{
    use InteractsWithAdmin;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The last saved footer, to know when there is something to save. @var array<string, mixed> */
    public array $baseline = [];

    public bool $justSaved = false;

    public function mount(AppFooterService $footer): void
    {
        abort_unless($this->can('cms.view'), 403);
        $this->baseline = $footer->config();
        $this->form = $this->baseline;
    }

    public function updated(): void
    {
        $this->justSaved = false;
    }

    public function pickDesign(string $design): void
    {
        abort_unless($this->can('cms.edit'), 403);
        if (in_array($design, AppFooterService::DESIGNS, true)) {
            $this->form['design'] = $design;
            $this->justSaved = false;
        }
    }

    public function move(int $index, int $by): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $items = array_values($this->form['items']);
        $to = $index + $by;
        if (! isset($items[$index], $items[$to])) {
            return;
        }
        [$items[$index], $items[$to]] = [$items[$to], $items[$index]];
        $this->form['items'] = $items;
        $this->justSaved = false;
    }

    public function addItem(): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $used = array_column($this->form['items'], 'key');
        $free = array_values(array_diff(AppFooterService::ITEMS, $used));
        if (count($this->form['items']) >= AppFooterService::MAX_ITEMS || ! $free) {
            return;
        }
        $this->form['items'][] = ['key' => $free[0], 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null];
        $this->justSaved = false;
    }

    public function removeItem(int $index): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $items = array_values($this->form['items']);
        if (count($items) <= AppFooterService::MIN_ENABLED || ($items[$index]['key'] ?? null) === 'home') {
            return;
        }
        unset($items[$index]);
        $this->form['items'] = array_values($items);
        $this->justSaved = false;
    }

    public function clearAccent(): void
    {
        $this->form['accent'] = null;
        $this->justSaved = false;
    }

    public function save(AppFooterService $footer, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->resetValidation();
        try {
            $clean = $footer->save($this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $m) {
                    $this->addError('form.'.$key, $m);
                }
            }

            return;
        }
        $audit->log('cms.footer_updated', 'AppFooter', $this->baseline, $clean);
        $this->baseline = $this->form = $clean;
        $this->justSaved = true;
        $this->toast(__('admin.saved'));
    }

    public function resetToDefault(AppFooterService $footer, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->resetValidation();
        $clean = $footer->reset();
        $audit->log('cms.footer_reset', 'AppFooter', $this->baseline, $clean);
        $this->baseline = $this->form = $clean;
        $this->justSaved = false;
        $this->toast(__('footer_admin.reset_done'));
    }

    /** Whether the form differs from what is saved (ignoring empty-vs-null label noise). */
    private function isDirty(AppFooterService $footer): bool
    {
        try {
            $current = $footer->validate($this->form);
        } catch (ValidationException) {
            return true; // not valid yet: let the admin press Save to see what is wrong
        }

        return $current !== $this->baseline;
    }

    public function render(AppFooterService $footer, ThemeService $themes)
    {
        return view('livewire.admin.content.app-footer', [
            'dirty' => $this->isDirty($footer),
            'primary' => $themes->active()?->colors['primary'] ?? '#8A1538',
            'canEdit' => $this->can('cms.edit'),
            'designs' => AppFooterService::DESIGNS, 'itemKeys' => AppFooterService::ITEMS, 'icons' => AppFooterService::ICONS,
            'defaultIcons' => AppFooterService::DEFAULT_ICONS, 'max' => AppFooterService::MAX_ITEMS,
        ])->title(__('footer_admin.title'));
    }
}
