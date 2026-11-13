<?php

namespace App\Livewire\Admin\Sales\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * A list screen whose rows open in a side window showing the record's own Livewire component in compact mode.
 *
 * The host declares the address-bar mirror and the gate:
 *
 *     #[Locked] #[Url(as: 'view', except: '')] public string $viewId = '';
 *     protected function findViewable(string $id): ?Model   // null when the viewer may not see it (same gate as the record's page)
 *
 * Opening is only ever done by the server (`openView`), so a made-up id, another branch's record or a stale deep link opens nothing.
 * The window keeps the record after closing so it slides out with its content; the key changes on every open, so a nested
 * component is rebuilt with fresh data each time.
 */
trait HasRecordWindow
{
    public bool $showView = false;

    #[Locked]
    public string $shownId = '';

    #[Locked]
    public int $viewSeq = 0;

    abstract protected function findViewable(string $id): ?Model;

    /** @param bool $quiet true for a deep link on page load: a record the viewer may not see simply does not open (no toast). */
    public function openView(string $id, bool $quiet = false): void
    {
        $record = Str::isUuid($id) ? $this->findViewable($id) : null;
        if (! $record) {
            $this->viewId = '';
            $this->shownId = '';
            $this->showView = false;
            if (! $quiet) {
                $this->toast(__('drawer_sales.not_available'), 'error');
            }

            return;
        }
        $this->viewId = $record->getKey();
        $this->shownId = $record->getKey();
        $this->viewSeq++;
        $this->showView = true;
    }

    /** Called for every way of closing (✕, Esc, scrim, Back): clears `?view=`. */
    public function closeView(): void
    {
        $this->showView = false;
        $this->viewId = '';
    }

    /** The record shown in the window, re-checked on every render (the stored id is never trusted blindly). */
    #[Computed]
    public function viewed(): ?Model
    {
        return $this->shownId !== '' && Str::isUuid($this->shownId) ? $this->findViewable($this->shownId) : null;
    }
}
