<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** Store design: the admin picks one complete design (colours and layout) for the web store and the mobile app with one press. */
class DesignIndex extends Component
{
    use InteractsWithAdmin;

    public bool $showConfirm = false;

    #[Locked]
    public ?string $pendingId = null;

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
        app(ThemeService::class)->seedDesigns();
    }

    public function ask(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $design = BrandTheme::where('kind', 'base')->where('is_enabled', true)->findOrFail($id);
        $this->pendingId = $design->is_default ? null : $design->id;
        $this->showConfirm = $this->pendingId !== null;
    }

    public function apply(ThemeService $themes): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $this->showConfirm = false;
        $design = $this->pendingId ? BrandTheme::where('kind', 'base')->find($this->pendingId) : null;
        $this->pendingId = null;
        if (! $design || $design->is_default) {
            return;
        }
        $previous = BrandTheme::where('is_default', true)->value('id');
        $themes->applyDesign($design);
        if ($previous) {
            $this->dispatch('toast', type: 'success', message: __('design_admin.switched'), undoLabel: __('admin.undo'), undoEvent: 'design-undo', undoParams: ['id' => $previous]);
        } else {
            $this->toast(__('design_admin.switched'));
        }
    }

    #[On('design-undo')]
    public function undo(string $id, ThemeService $themes): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $design = BrandTheme::where('kind', 'base')->find($id);
        if ($design && ! $design->is_default) {
            $themes->applyDesign($design);
            $this->toast(__('design_admin.switched'));
        }
    }

    public function render(ThemeService $themes)
    {
        $active = $themes->active();
        $store = rtrim((string) (config('platform.storefront_url') ?: config('app.url')), '/');

        return view('livewire.admin.content.designs', [
            'designs' => BrandTheme::where('kind', 'base')->where('is_enabled', true)->orderByDesc('is_default')->orderBy('created_at')->orderBy('key')->get(),
            'eventLook' => $active && $active->kind !== 'base' ? $active : null,
            'canEdit' => $this->can('cms.edit'), 'storeUrl' => $store,
            'pending' => $this->pendingId ? BrandTheme::find($this->pendingId) : null,
        ])->title(__('design_admin.title'));
    }
}
