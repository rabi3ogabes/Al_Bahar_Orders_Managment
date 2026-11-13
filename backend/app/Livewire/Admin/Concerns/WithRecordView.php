<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * A list row opens in a side window beside the list (<x-admin.dialog wire:model.live="showView">) instead of on its own page.
 * `?view=<id>` keeps it reloadable, shareable and Back-friendly; the full page route keeps working and the window offers "Open full page".
 *
 * The component supplies viewable() with the SAME scoping the full page uses. An id the viewer may not see (or that is not an id at all)
 * never opens and leaves no trace, and the id is re-checked on every render, so a tampered `viewId` shows nothing.
 */
trait WithRecordView
{
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    public bool $showView = false;

    /** May the signed-in staff member see this record? (UUID already validated.) */
    abstract protected function viewable(string $id): bool;

    /** Called each time a record window opens (click, `?view=` link, reload) so the component can prefill its own fields. */
    protected function viewOpened(string $id): void {}

    /** Livewire trait hook: a page opened on `?view=<id>` (reload, shared link, notification) starts with the window open. */
    public function mountWithRecordView(): void
    {
        $this->showView = $this->viewId !== '' && $this->viewAllowed($this->viewId);
        if ($this->showView) {
            $this->viewOpened($this->viewId);
        } else {
            $this->viewId = '';
        }
    }

    public function openView(string $id): void
    {
        if (! $this->viewAllowed($id)) {
            return;
        }
        $this->viewId = $id;
        $this->showView = true;
        $this->viewOpened($id);
    }

    public function closeView(): void
    {
        $this->showView = false;
        $this->viewId = '';
    }

    /** The window's ✕, Esc, scrim and Back all end up here (wire:model.live): drop the id so the URL is clean again. */
    public function updatedShowView(bool $open): void
    {
        if (! $open) {
            $this->viewId = '';
        }
    }

    /** The id to show, or null when the window is closed or the id is not visible to this viewer. */
    protected function viewing(): ?string
    {
        return $this->showView && $this->viewAllowed($this->viewId) ? $this->viewId : null;
    }

    private function viewAllowed(string $id): bool
    {
        return Str::isUuid($id) && $this->viewable($id);
    }
}
