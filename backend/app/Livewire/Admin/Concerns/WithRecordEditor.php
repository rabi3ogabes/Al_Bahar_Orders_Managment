<?php

namespace App\Livewire\Admin\Concerns;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * A list whose rows are EDITED in a side window beside the list instead of on their own page (Employees, Roles, Branches).
 * The full-page editor keeps its route: the window offers "Open full page" and the two share one editor component (its `embedded` flag only
 * changes the layout), so a rule can never differ between them.
 *
 *  - `?edit=<id>` is an open edit window, `?new=1` an open "new" window: reload, share, notification links and Back all work, and the page
 *    behind keeps its search, filters and scroll position.
 *  - The component decides who may open what with the SAME rule the full page uses (editable() / creatable()); an id the viewer may not see
 *    (or that does not exist) never opens and leaves no trace, and the id is re-checked on every render, so a tampered `editId` shows nothing.
 *  - The nested editor tells the list what happened through three events: `record-created` (id), `record-changed` and `record-deleted`.
 */
trait WithRecordEditor
{
    #[Url(as: 'edit', except: '')]
    public string $editId = '';

    public bool $showEditor = false;

    #[Url(as: 'new', except: false)]
    public bool $creating = false;

    /** The tab a just-created record's window opens on (set by `record-created`, cleared when the window closes). */
    public string $landing = '';

    /** May the signed-in staff member open this record? Same scoping as the full page (the id is only checked to be a plain token here). */
    abstract protected function editable(string $id): bool;

    /** May the signed-in staff member create one? */
    abstract protected function creatable(): bool;

    /** Livewire trait hook: a page opened on `?edit=` or `?new=1` (reload, shared link) starts with the window open. */
    public function mountWithRecordEditor(): void
    {
        $this->showEditor = $this->editId !== '' && $this->editAllowed($this->editId);
        if (! $this->showEditor) {
            $this->editId = '';
        }
        $this->creating = $this->creating && ! $this->showEditor && $this->creatable();
    }

    public function openEditor(string $id): void
    {
        if (! $this->editAllowed($id)) {
            return;
        }
        $this->creating = false;
        $this->editId = $id;
        $this->showEditor = true;
    }

    public function openCreate(): void
    {
        if (! $this->creatable()) {
            return;
        }
        $this->showEditor = false;
        $this->editId = '';
        $this->creating = true;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->editId = '';
        $this->landing = '';
    }

    public function closeCreate(): void
    {
        $this->creating = false;
    }

    /** The window's ✕, Esc, scrim and Back all end up here (wire:model.live): drop the id so the URL is clean again. */
    public function updatedShowEditor(bool $open): void
    {
        if (! $open) {
            $this->editId = '';
            $this->landing = '';
        }
    }

    /** A "new" window created its record: close it and open that record's window, on the tab the editor asks for (if any). */
    #[On('record-created')]
    public function recordCreated(string $id, string $tab = ''): void
    {
        $this->creating = false;
        $this->openEditor($id);
        $this->landing = $this->showEditor ? $tab : '';
    }

    /** The nested editor saved something: nothing to do but render again, so the row behind shows the new values. */
    #[On('record-changed')]
    public function recordChanged(): void {}

    #[On('record-deleted')]
    public function recordDeleted(): void
    {
        $this->closeEditor();
    }

    /** The id of the record whose window is open, or null when closed (or the viewer may not open it). */
    protected function editing(): ?string
    {
        return $this->showEditor && $this->editAllowed($this->editId) ? $this->editId : null;
    }

    private function editAllowed(string $id): bool
    {
        return $id !== '' && strlen($id) <= 64 && preg_match('/^[A-Za-z0-9-]+$/', $id) === 1 && $this->editable($id);
    }
}
